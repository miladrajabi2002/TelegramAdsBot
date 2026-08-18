<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_user_id', 'disk', 'storage_key', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'is_encrypted', 'expires_at'])]
class PrivateFile extends Model
{
    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean', 'expires_at' => 'datetime'];
    }
}
