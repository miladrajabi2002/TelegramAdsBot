<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'is_public', 'updated_by'])]
class Setting extends Model
{
    protected function casts(): array { return ['value' => 'array', 'is_public' => 'boolean']; }
}
