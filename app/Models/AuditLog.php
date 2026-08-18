<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'before_redacted',
    'after_redacted', 'reason', 'correlation_id', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return ['before_redacted' => 'array', 'after_redacted' => 'array'];
    }

    public function actor(): MorphTo { return $this->morphTo(); }
    public function subject(): MorphTo { return $this->morphTo(); }
}
