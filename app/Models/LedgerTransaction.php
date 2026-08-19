<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'type', 'reference_type', 'reference_id', 'idempotency_key',
    'description', 'created_by_admin_id',
])]
class LedgerTransaction extends Model
{
    protected static function booted(): void
    {
        static::creating(function (LedgerTransaction $transaction): void {
            $transaction->public_id ??= (string) Str::uuid();
        });
    }

    public function reference(): MorphTo { return $this->morphTo(); }
    public function entries(): HasMany { return $this->hasMany(LedgerEntry::class); }
}
