<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'slug', 'title_fa', 'title_en', 'description_fa', 'description_en',
    'icon', 'is_active', 'sort_order',
])]
class TargetCategory extends Model
{
    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(SuggestedChannel::class, 'target_category_channels')
            ->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    /**
     * Convenience accessor — the admin UI no longer collects separate
     * Persian/English titles, so we expose a single `title` attribute
     * that returns whichever variant is populated (falls back to fa).
     */
    public function getTitleAttribute(): string
    {
        return (string) ($this->attributes['title_fa'] ?? $this->attributes['title_en'] ?? '');
    }
}
