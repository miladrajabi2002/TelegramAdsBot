<?php

namespace App\Models;

use App\Enums\KycLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'telegram_user_id', 'telegram_username', 'first_name', 'last_name', 'display_name',
    'locale', 'photo_url', 'phone', 'phone_verified_at', 'kyc_level', 'account_status',
    'last_seen_at', 'risk_flags', 'email', 'password',
])]
#[Hidden(['password', 'remember_token'])]
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
            'kyc_level' => KycLevel::class,
            'risk_flags' => 'array',
            'password' => 'hashed',
        ];
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

    public function latestKycApplication(): ?KycApplication
    {
        return $this->kycApplications()->latest('version')->first();
    }

    public function canUseRialPayments(): bool
    {
        return $this->kyc_level === KycLevel::RialVerified
            && $this->phone_verified_at !== null
            && $this->fundingCards()->where('status', 'approved')->exists();
    }
}
