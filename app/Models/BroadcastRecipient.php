<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['broadcast_id', 'user_id', 'status', 'attempts', 'sent_at', 'retry_at', 'error'])]
class BroadcastRecipient extends Model
{
    protected function casts(): array { return ['sent_at' => 'datetime', 'retry_at' => 'datetime']; }
    public function broadcast(): BelongsTo { return $this->belongsTo(Broadcast::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
