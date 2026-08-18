<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['support_ticket_id', 'sender_type', 'sender_id', 'body', 'private_file_id', 'read_at'])]
class TicketMessage extends Model
{
    protected function casts(): array { return ['read_at' => 'datetime']; }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function sender(): MorphTo { return $this->morphTo(); }
}
