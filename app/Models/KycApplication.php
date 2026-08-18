<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'version', 'status', 'legal_name_encrypted', 'legal_name_search',
    'national_id_encrypted', 'national_id_hmac', 'user_note', 'admin_note',
    'submitted_at', 'reviewed_at', 'reviewed_by', 'lock_version',
])]
class KycApplication extends Model
{
    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'legal_name_encrypted' => 'encrypted',
            'national_id_encrypted' => 'encrypted',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(Admin::class, 'reviewed_by'); }
    public function documents(): HasMany { return $this->hasMany(KycDocument::class); }
    public function cards(): HasMany { return $this->hasMany(FundingCard::class); }
    public function reviews(): HasMany { return $this->hasMany(KycReview::class); }
}
