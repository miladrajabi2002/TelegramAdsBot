<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_revision_id', 'suggested_channel_id', 'source', 'channel_username',
    'channel_title', 'public_url', 'members_snapshot', 'validation_status',
])]
class CampaignTarget extends Model
{
    public function revision(): BelongsTo { return $this->belongsTo(CampaignRevision::class, 'campaign_revision_id'); }
    public function suggestedChannel(): BelongsTo { return $this->belongsTo(SuggestedChannel::class); }
}
