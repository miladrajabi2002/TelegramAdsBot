<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'type', 'status', 'assigned_admin_id', 'due_at', 'completed_at', 'context'])]
class OperatorTask extends Model
{
    protected function casts(): array { return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'context' => 'array']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(Admin::class, 'assigned_admin_id'); }
}
