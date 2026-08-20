<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'order_id', 'revision_no', 'internal_title', 'ad_text', 'destination_type',
    'destination_url', 'placement_type', 'targeting_payload', 'impression_goal',
    'frequency_cap', 'daily_view_limit_per_user', 'plan', 'cpm_gram', 'language',
    'ad_media_path', 'ad_media_type', 'ad_media_disk', 'search_keywords', 'is_locked',
])]
class CampaignRevision extends Model
{
    protected static function booted(): void
    {
        static::creating(function (CampaignRevision $revision): void {
            // The customer edit flow creates a brand-new revision but currently
            // does not submit a new ad_media field. Preserve the previous media
            // automatically so a support-requested correction does not make the
            // image/video disappear from the new current revision.
            if ($revision->ad_media_path || ! $revision->order_id) {
                return;
            }

            $order = Order::query()->with('currentRevision')->find($revision->order_id);
            $previous = $order?->currentRevision;
            if (! $previous || ! $previous->ad_media_path) {
                return;
            }

            $revision->ad_media_path = $previous->getRawOriginal('ad_media_path') ?: $previous->ad_media_path;
            $revision->ad_media_type = $previous->ad_media_type;
            $revision->ad_media_disk = $previous->ad_media_disk ?: 'local';
        });
    }

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'cpm_gram' => 'decimal:9',
            'targeting_payload' => 'array',
            'search_keywords' => 'array',
            'daily_view_limit_per_user' => 'integer',
        ];
    }

    /**
     * Older uploads were written with UploadedFile::storeAs('local', $path),
     * which placed the bytes under storage/app/private/local/... while the DB
     * stored only ad-media/.... AdminOrderController correctly reads from the
     * local disk, so transparently resolve that legacy prefix when necessary.
     */
    protected function adMediaPath(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): mixed {
                if (! is_string($value) || trim($value) === '') {
                    return $value;
                }

                $path = ltrim($value, '/');
                $disk = (string) ($this->attributes['ad_media_disk'] ?? 'local');
                if ($disk === '' || ! array_key_exists($disk, (array) config('filesystems.disks', []))) {
                    $disk = 'local';
                }

                try {
                    if (Storage::disk($disk)->exists($path)) {
                        return $path;
                    }

                    if ($disk === 'local') {
                        $legacyPath = 'local/'.$path;
                        if (Storage::disk('local')->exists($legacyPath)) {
                            return $legacyPath;
                        }
                    }
                } catch (Throwable) {
                    // Keep the stored value. The authenticated media endpoint
                    // will return a normal 404 instead of exploding during model
                    // hydration if the storage backend itself is unavailable.
                }

                return $path;
            },
        );
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function targets(): HasMany { return $this->hasMany(CampaignTarget::class); }
    public function telegramSubmissions(): HasMany { return $this->hasMany(TelegramSubmission::class); }
}
