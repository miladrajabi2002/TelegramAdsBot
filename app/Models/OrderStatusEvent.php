<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason_code', 'note', 'correlation_id'])]
class OrderStatusEvent extends Model
{
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function actor(): MorphTo { return $this->morphTo(); }
}
