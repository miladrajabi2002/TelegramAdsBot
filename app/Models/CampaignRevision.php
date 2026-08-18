<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'revision_no', 'internal_title', 'ad_text', 'destination_type',
    'destination_url', 'placement_type', 'targeting_payload', 'impression_goal',
    'frequency_cap', 'plan', 'cpm_gram', 'language', 'is_locked',
])]
class CampaignRevision extends Model
{
    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'cpm_gram' => 'decimal:9',
            'targeting_payload' => 'array',
        ];
    }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function targets(): HasMany { return $this->hasMany(CampaignTarget::class); }
    public function telegramSubmissions(): HasMany { return $this->hasMany(TelegramSubmission::class); }
}
