<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'payment_intent_id', 'type', 'amount_minor', 'currency', 'status',
    'destination_masked', 'reason', 'admin_note', 'processed_by',
    'ledger_transaction_id', 'processed_at',
])]
class PayoutRequest extends Model
{
    protected function casts(): array { return ['processed_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function paymentIntent(): BelongsTo { return $this->belongsTo(PaymentIntent::class); }
    public function processor(): BelongsTo { return $this->belongsTo(Admin::class, 'processed_by'); }
    public function ledgerTransaction(): BelongsTo { return $this->belongsTo(LedgerTransaction::class); }
}
