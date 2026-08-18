<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kyc_application_id', 'admin_id', 'decision', 'reason_code', 'note', 'checklist'])]
class KycReview extends Model
{
    protected function casts(): array { return ['checklist' => 'array']; }
    public function application(): BelongsTo { return $this->belongsTo(KycApplication::class, 'kyc_application_id'); }
    public function admin(): BelongsTo { return $this->belongsTo(Admin::class); }
}
