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
}
