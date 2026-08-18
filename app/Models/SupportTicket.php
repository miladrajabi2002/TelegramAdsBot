<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'user_id', 'order_id', 'subject', 'status', 'priority', 'assigned_admin_id', 'last_message_at'])]
class SupportTicket extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (SupportTicket $ticket) => $ticket->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array { return ['last_message_at' => 'datetime']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(Admin::class, 'assigned_admin_id'); }
    public function messages(): HasMany { return $this->hasMany(TicketMessage::class); }
}
