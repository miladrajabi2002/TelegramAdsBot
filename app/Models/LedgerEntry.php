<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ledger_transaction_id', 'ledger_account_id', 'direction', 'amount_minor', 'currency'])]
class LedgerEntry extends Model
{
    public function transaction(): BelongsTo { return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id'); }
    public function account(): BelongsTo { return $this->belongsTo(LedgerAccount::class, 'ledger_account_id'); }
}
