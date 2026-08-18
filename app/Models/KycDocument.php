<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kyc_application_id', 'private_file_id', 'kind'])]
class KycDocument extends Model
{
    public function application(): BelongsTo { return $this->belongsTo(KycApplication::class, 'kyc_application_id'); }
    public function file(): BelongsTo { return $this->belongsTo(PrivateFile::class, 'private_file_id'); }
}
