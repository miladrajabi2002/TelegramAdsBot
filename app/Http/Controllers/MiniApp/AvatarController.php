<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Serve Telegram profile photos through the application.
 *
 * Telegram file download URLs contain the bot token:
 *   https://api.telegram.org/file/bot<TOKEN>/<file_path>
 *
 * Those URLs must never be returned to a browser. This controller downloads
 * the bytes server-side, caches a base64 representation, and returns only the
 * image bytes to the authenticated Mini App user or an authenticated admin.
 */
class AvatarController extends Controller
{
    private const IMAGE_CACHE_TTL_SECONDS = 1800;
    private const NO_PHOTO_CACHE_TTL_SECONDS = 300;
    private const NO_PHOTO_MARKER = '__NO_PHOTO__';

    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    public function show(int $userId): Response
    {
        $miniAppUser = auth('web')->user();
        $admin = auth('admin')->user();

        // Admins may view any user avatar. Mini App users may only view their
        // own avatar. This also prevents public user-id enumeration.
        abort_unless(
            $admin !== null || ($miniAppUser !== null && (int) $miniAppUser->getKey() === $userId),
            404,
        );

        $user = User::find($userId);
        abort_unless($user, 404);

        $cacheKey = "avatar:image:{$userId}";
        $cached = Cache::get($cacheKey);

        if ($cached === self::NO_PHOTO_MARKER) {
            abort(404);
        }

        if (is_array($cached)) {
            $encoded = $cached['base64'] ?? null;
            $mime = $cached['mime'] ?? null;
            if (is_string($encoded) && is_string($mime)) {
                $bytes = base64_decode($encoded, true);
                if (is_string($bytes) && $bytes !== '' && $this->isAllowedImageMime($mime)) {
                    return $this->imageResponse($bytes, $mime);
                }
            }

            // Corrupted/old cache entry: discard and re-resolve.
            Cache::forget($cacheKey);
        }

        $telegramUserId = (int) $user->telegram_user_id;
        if ($telegramUserId <= 0) {
            Cache::put($cacheKey, self::NO_PHOTO_MARKER, self::NO_PHOTO_CACHE_TTL_SECONDS);
            abort(404);
        }

        try {
            $photo = $this->botClient->downloadLatestUserProfilePhoto($telegramUserId);
        } catch (\Throwable $e) {
            Log::debug('AvatarController: profile photo download failed', [
                'user_id' => $user->getKey(),
                'telegram_user_id' => $telegramUserId,
                'exception' => $e->getMessage(),
            ]);
            $photo = null;
        }

        if (! is_array($photo) || ! is_string($photo['bytes'] ?? null) || $photo['bytes'] === '') {
            Cache::put($cacheKey, self::NO_PHOTO_MARKER, self::NO_PHOTO_CACHE_TTL_SECONDS);
            abort(404);
        }

        $bytes = $photo['bytes'];
        $mime = $this->detectImageMime($bytes);

        if ($mime === null) {
            Log::warning('AvatarController: Telegram returned a non-image avatar payload', [
                'user_id' => $user->getKey(),
            ]);
            Cache::put($cacheKey, self::NO_PHOTO_MARKER, self::NO_PHOTO_CACHE_TTL_SECONDS);
            abort(404);
        }

        Cache::put($cacheKey, [
            'base64' => base64_encode($bytes),
            'mime' => $mime,
        ], self::IMAGE_CACHE_TTL_SECONDS);

        // Remove any legacy token-bearing Telegram CDN URL left in the DB by
        // older versions. The migration also performs this cleanup globally.
        if (is_string($user->photo_url) && str_contains($user->photo_url, 'api.telegram.org/file/bot')) {
            try {
                $user->forceFill(['photo_url' => null])->saveQuietly();
            } catch (\Throwable $e) {
                Log::debug('AvatarController: could not clear legacy photo_url', [
                    'user_id' => $user->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->imageResponse($bytes, $mime);
    }

    private function detectImageMime(string $bytes): ?string
    {
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        } catch (\Throwable) {
            return null;
        }

        return is_string($mime) && $this->isAllowedImageMime($mime) ? $mime : null;
    }

    private function isAllowedImageMime(string $mime): bool
    {
        return in_array(strtolower(trim(explode(';', $mime, 2)[0])), [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ], true);
    }

    private function imageResponse(string $bytes, string $mime): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
