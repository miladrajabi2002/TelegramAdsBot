<?php

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'user_id', 'current_revision_id', 'status', 'payment_status', 'funding_mode',
    'media_budget_irr', 'service_markup_bps', 'service_fee_irr', 'gateway_fee_irr',
    'total_irr', 'gram_amount', 'usd_amount', 'usd_to_irr_rate', 'gram_to_usd_rate',
    'conversion_margin_bps', 'rate_source', 'quoted_at', 'quote_expires_at', 'planned_start_at',
    'funded_at', 'completed_at',
])]
class Order extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'quote_expires_at' => 'datetime',
            'quoted_at' => 'datetime',
            'planned_start_at' => 'datetime',
            'funded_at' => 'datetime',
            'completed_at' => 'datetime',
            'gram_amount' => 'decimal:9',
            'usd_amount' => 'decimal:2',
            'usd_to_irr_rate' => 'decimal:4',
            'gram_to_usd_rate' => 'decimal:8',
        ];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function currentRevision(): BelongsTo { return $this->belongsTo(CampaignRevision::class, 'current_revision_id'); }
    public function revisions(): HasMany { return $this->hasMany(CampaignRevision::class); }
    public function metrics(): HasMany { return $this->hasMany(CampaignMetricSnapshot::class); }
    public function statusEvents(): HasMany { return $this->hasMany(OrderStatusEvent::class); }
    public function paymentIntents(): HasMany { return $this->hasMany(PaymentIntent::class); }
    public function operatorTasks(): HasMany { return $this->hasMany(OperatorTask::class); }

    public function latestMetrics(): ?CampaignMetricSnapshot
    {
        return $this->metrics()->latest('as_of_at')->first();
    }

    public function totalToman(): int
    {
        return intdiv($this->total_irr, 10);
    }
}
