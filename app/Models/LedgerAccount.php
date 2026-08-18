<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['owner_type', 'owner_id', 'currency', 'type', 'normal_balance', 'name', 'is_active'])]
class LedgerAccount extends Model
{
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function owner(): MorphTo { return $this->morphTo(); }
    public function entries(): HasMany { return $this->hasMany(LedgerEntry::class); }

    public function balance(): int
    {
        $debits = (int) $this->entries()->where('direction', 'debit')->sum('amount_minor');
        $credits = (int) $this->entries()->where('direction', 'credit')->sum('amount_minor');

        return $this->normal_balance === 'credit' ? $credits - $debits : $debits - $credits;
    }
}
