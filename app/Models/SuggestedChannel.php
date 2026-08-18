<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'telegram_chat_id', 'username', 'title', 'public_url', 'avatar_url', 'language',
    'members_count', 'eligibility_status', 'is_featured', 'is_active',
    'last_verified_at', 'internal_note',
])]
class SuggestedChannel extends Model
{
    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'is_active' => 'boolean', 'last_verified_at' => 'datetime'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TargetCategory::class, 'target_category_channels')
            ->withPivot('position')->withTimestamps();
    }
}
