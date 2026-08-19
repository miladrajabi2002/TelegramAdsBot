<?php

namespace App\Models;

use App\Enums\KycLevel;
use App\Services\Telegram\TelegramBotClient;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'telegram_user_id', 'telegram_username', 'first_name', 'last_name', 'display_name',
    'locale', 'locale_set_at', 'magic_token', 'photo_url', 'phone', 'phone_verified_at',
    'kyc_level', 'account_status', 'last_seen_at', 'risk_flags', 'email', 'password',
])]
#[Hidden(['password', 'remember_token', 'magic_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'locale_set_at' => 'datetime',
            'kyc_level' => KycLevel::class,
            'risk_flags' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * Auto-generate a magic_token when a new user is created.
     *
     * This token is the "second factor" for Mini App authentication: even
     * when Telegram's initData is unavailable (user opens the URL directly,
     * older client, network glitch), the bot can still authenticate the
     * user via the token included in the inline-button URL.
     *
     * The token is rotated on every /start via `rotateMagicToken()`, so a
     * leaked URL only grants access until the user re-engages with the bot.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->magic_token)) {
                $user->magic_token = Str::random(64);
            }
        });
    }

    /**
     * Has the user EXPLICITLY chosen a language (vs. just having a default
     * inferred from Telegram's language_code)?
     */
    public function hasChosenLocale(): bool
    {
        return $this->locale_set_at !== null
            && in_array($this->locale, ['fa', 'en'], true);
    }

    /**
     * Rotate the user's magic_token. Call this whenever the user re-engages
     * with the bot (/start) so any previously-leaked URLs stop working.
     */
    public function rotateMagicToken(): static
    {
        $this->magic_token = Str::random(64);
        $this->save();

        return $this;
    }

    public function kycApplications(): HasMany
    {
        return $this->hasMany(KycApplication::class);
    }

    public function fundingCards(): HasMany
    {
        return $this->hasMany(FundingCard::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function ledgerAccounts(): MorphMany
    {
        return $this->morphMany(LedgerAccount::class, 'owner');
    }

    /**
     * The user's most recent KYC application, expressed as a real
     * HasOne relationship so it works with `data_get`, lazy-loading,
     * and eager-loading. The previous implementation returned ?KycApplication
     * directly, which broke when accessed via `data_get($user, 'latestKycApplication')`
     * because Laravel tried to treat it as a relationship and complained
     * about "must return a relationship instance, but null was returned".
     *
     * Use `$user->latestKycApplication` (property access) — this returns
     * the model OR null, and never throws.
     */
    public function latestKycApplication(): HasOne
    {
        return $this->hasOne(KycApplication::class)
            ->orderByDesc('version')
            ->limit(1);
    }

    public function canUseRialPayments(): bool
    {
        return $this->kyc_level === KycLevel::RialVerified
            && $this->phone_verified_at !== null
            && $this->fundingCards()->where('status', 'approved')->exists();
    }

    /**
     * Refresh the user's Telegram profile photo URL.
     *
     * Asks Telegram's Bot API for the latest profile photo, then persists
     * the public download URL (https://api.telegram.org/file/bot<token>/<path>)
     * directly on the `users.photo_url` column. We never download the
     * bytes — the <img src> loads the photo straight from Telegram's CDN.
     *
     * TTL: Telegram's getFile() URL is valid for ~1 hour, so we skip the
     * refresh entirely if `photo_url` already points at Telegram's CDN and
     * the row was last touched less than 30 minutes ago. This avoids hitting
     * the Bot API on every single page load.
     *
     * Pass `force: true` to bypass the freshness check — used by the admin
     * "Refresh photo" button so the operator can force a re-fetch even when
     * the URL is technically still fresh (e.g. the user uploaded a new
     * Telegram profile photo and the admin wants to see it now).
     *
     * Returns true when a URL is available (either freshly fetched or
     * still valid from a previous fetch); false when the user has no photo
     * or the Bot API could not be reached.
     */
    public function refreshTelegramPhotoUrl(TelegramBotClient $bot, bool $force = false): bool
    {
        // Skip the Bot API call entirely when we already have a fresh URL
        // AND the caller didn't explicitly force a re-fetch.
        if (! $force
            && is_string($this->photo_url)
            && $this->photo_url !== ''
            && str_contains($this->photo_url, 'api.telegram.org/file/bot')) {
            $updated = $this->updated_at ?? now();
            try {
                if ($updated->diffInMinutes(now()) < 30) {
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to a fresh fetch if the timestamp is unreadable.
            }
        }

        $telegramUserId = (int) $this->telegram_user_id;
        if ($telegramUserId <= 0) {
            return false;
        }

        try {
            $url = $bot->getLatestUserProfilePhotoUrl($telegramUserId);
        } catch (\Throwable) {
            return false;
        }

        if (! is_string($url) || $url === '') {
            // The user has no Telegram profile photo. Leave any stale URL in
            // place — overwriting with null would erase a previously working
            // (if expired) URL without giving the UI a chance to fall back to
            // the initial-letter avatar gracefully.
            return false;
        }

        if ($url === $this->photo_url) {
            // Touch updated_at so the next 30-min window starts now.
            try {
                $this->forceFill(['updated_at' => now()])->saveQuietly();
            } catch (\Throwable) {
                // Best-effort.
            }

            return true;
        }

        try {
            // Save the new URL AND bump updated_at so the freshness window
            // applies to the new URL.
            $this->forceFill([
                'photo_url' => $url,
                'updated_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable) {
            // Persisting is best-effort — the URL is still usable in-memory.
        }

        return true;
    }
}
