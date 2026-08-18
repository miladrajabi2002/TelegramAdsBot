<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_intent_id', 'provider_reference', 'authority', 'redirect_url',
    'verify_code', 'provider_response', 'verified_at',
])]
class PaymentAttempt extends Model
{
    protected function casts(): array { return ['provider_response' => 'array', 'verified_at' => 'datetime']; }
    public function intent(): BelongsTo { return $this->belongsTo(PaymentIntent::class, 'payment_intent_id'); }
}
