<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'user_id', 'order_id', 'purpose', 'provider', 'merchant_reference',
    'amount_minor', 'currency', 'status', 'expires_at', 'verified_at', 'metadata',
])]
class PaymentIntent extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PaymentIntent $intent): void {
            $intent->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function attempts(): HasMany { return $this->hasMany(PaymentAttempt::class); }
}
