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

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->magic_token)) {
                $user->magic_token = Str::random(64);
            }
        });
    }

    public function hasChosenLocale(): bool
    {
        return $this->locale_set_at !== null
            && in_array($this->locale, ['fa', 'en'], true);
    }

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
     * Backward-compatible avatar refresh probe.
     *
     * Older builds persisted Telegram file download URLs in `photo_url`.
     * Those URLs contain the bot token and must never be stored or exposed.
     * This method now only checks whether Telegram reports a profile photo
     * and clears any legacy token-bearing URL from the model.
     *
     * Avatar bytes themselves are fetched and cached by AvatarController.
     */
    public function refreshTelegramPhotoUrl(TelegramBotClient $bot, bool $force = false): bool
    {
        if (is_string($this->photo_url) && str_contains($this->photo_url, 'api.telegram.org/file/bot')) {
            try {
                $this->forceFill(['photo_url' => null])->saveQuietly();
            } catch (\Throwable) {
                // Best-effort cleanup. The avatar helper ignores photo_url now.
            }
        }

        $telegramUserId = (int) $this->telegram_user_id;
        if ($telegramUserId <= 0) {
            return false;
        }

        try {
            $photos = $bot->getUserProfilePhotos($telegramUserId, 1);
        } catch (\Throwable) {
            return false;
        }

        return is_array($photos)
            && isset($photos['photos'][0])
            && is_array($photos['photos'][0])
            && $photos['photos'][0] !== [];
    }
}
