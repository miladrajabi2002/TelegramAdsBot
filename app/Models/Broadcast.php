<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['admin_id', 'title', 'message', 'audience_filters', 'status', 'scheduled_at', 'started_at', 'completed_at'])]
class Broadcast extends Model
{
    protected function casts(): array
    {
        return ['audience_filters' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function admin(): BelongsTo { return $this->belongsTo(Admin::class); }
    public function recipients(): HasMany { return $this->hasMany(BroadcastRecipient::class); }
}
