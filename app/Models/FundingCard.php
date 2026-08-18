<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'kyc_application_id', 'pan_encrypted', 'pan_hmac', 'bin', 'last4',
    'holder_name_encrypted', 'holder_name_search', 'status', 'verification_method',
    'verification_result', 'verified_at',
])]
class FundingCard extends Model
{
    protected function casts(): array
    {
        return [
            'pan_encrypted' => 'encrypted',
            'holder_name_encrypted' => 'encrypted',
            'verification_result' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function application(): BelongsTo { return $this->belongsTo(KycApplication::class, 'kyc_application_id'); }

    public function masked(): string
    {
        return $this->bin.'******'.$this->last4;
    }
}
