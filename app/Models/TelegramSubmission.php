<?php

namespace App\Models;

use App\Enums\TelegramSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_revision_id', 'external_ad_id', 'external_account_label', 'status',
    'rejection_reason', 'proof_file_id', 'submitted_by', 'submitted_at', 'resolved_at',
])]
class TelegramSubmission extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TelegramSubmissionStatus::class,
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo { return $this->belongsTo(CampaignRevision::class, 'campaign_revision_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(Admin::class, 'submitted_by'); }
}
