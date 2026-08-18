<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'as_of_at', 'impressions', 'joins', 'bot_starts', 'spend_gram',
    'remaining_budget_gram', 'source', 'proof_file_id', 'recorded_by', 'supersedes_id',
])]
class CampaignMetricSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'as_of_at' => 'datetime',
            'spend_gram' => 'decimal:9',
            'remaining_budget_gram' => 'decimal:9',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
