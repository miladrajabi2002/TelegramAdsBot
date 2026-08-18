<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Serves the user's Telegram profile photo with on-demand caching.
 *
 * Why this design:
 *   - Telegram's `getFile()` returns a one-hour URL that includes the bot
 *     token (e.g. https://api.telegram.org/file/bot<TOKEN>/photos/...). Putting
 *     that URL into an <img src> would leak the bot token to anyone who
 *     inspects the rendered HTML — that's an absolute NO.
 *   - We can't rely on a queue job running asynchronously because not every
 *     deployment runs `php artisan queue:work`. The user reported the avatar
 *     always showing a "?" because the `RefreshUserProfilePhoto` job never
 *     executed on their server.
 *
 * What this controller does instead:
 *   1. On the FIRST request for /avatars/{userId}, if the cached photo file
 *      does not exist locally, we synchronously fetch it from Telegram's Bot
 *      API (getUserProfilePhotos → getFile → download) and store it under
 *      `storage/app/avatars/{userId}.{ext}`.
 *   2. Subsequent requests for the same user are served straight from disk
 *      with cache headers — no Telegram API call, no latency.
 *   3. We also persist the public URL `https://your-domain/avatars/{id}.{ext}`
 *      on `users.photo_url` so the Blade templates can render it directly.
 *
 * This route is PUBLIC (no auth middleware) because <img src> tags don't
 * always forward the Telegram WebView cookie. The endpoint is rate-limited
 * by Laravel's throttler; the data is just an avatar image (not sensitive).
 */
class AvatarController extends Controller
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    public function show(int $userId): Response
    {
        $user = User::find($userId);
        abort_unless($user, 404);

        // If we already have a cached photo on disk, serve it directly.
        foreach (self::EXTENSIONS as $ext) {
            $key = "avatars/{$userId}.{$ext}";
            if (Storage::disk('local')->exists($key)) {
                return $this->serve($key, $ext);
            }
        }

        // Otherwise, try to fetch from Telegram right now.
        $fetched = $this->fetchAndCacheFromTelegram($user);
        if ($fetched !== null) {
            return $this->serve($fetched['key'], $fetched['ext']);
        }

        // No photo (or fetch failed) — return a 404 so the <img> falls back
        // to whatever onerror/onload the client uses (e.g. the initial-letter
        // avatar in the topbar).
        abort(404);
    }

    /**
     * @return array{key: string, ext: string}|null
     */
    private function fetchAndCacheFromTelegram(User $user): ?array
    {
        $telegramUserId = (int) $user->telegram_user_id;
        if ($telegramUserId <= 0) {
            return null;
        }

        try {
            $photos = $this->botClient->getUserProfilePhotos($telegramUserId, 1);
            if (! isset($photos['photos'][0]) || ! is_array($photos['photos'][0])) {
                return null;
            }
            $sizes = $photos['photos'][0];
            $largest = end($sizes);
            $fileId = $largest['file_id'] ?? null;
            if (! is_string($fileId) || $fileId === '') {
                return null;
            }

            $file = $this->botClient->getFile($fileId);
            $filePath = $file['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '') {
                return null;
            }

            $token = (string) config('services.telegram.bot_token');
            if ($token === '') {
                return null;
            }
            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

            // Short timeout so a slow Telegram CDN doesn't hold the
            // <img> request open for too long.
            $response = Http::timeout(6)->retry(1, 100)->get($downloadUrl);
            if (! $response->ok()) {
                return null;
            }
            $bytes = $response->body();
            if ($bytes === '' || strlen($bytes) > 8 * 1024 * 1024) {
                return null;
            }

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
            if ($ext === 'jpeg') $ext = 'jpg';
            if (! in_array($ext, self::EXTENSIONS, true)) $ext = 'jpg';

            $key = "avatars/{$user->getKey()}.{$ext}";
            Storage::disk('local')->put($key, $bytes);

            // Clean up any leftover file with a different extension from a
            // previous fetch (user changed their profile photo).
            foreach (self::EXTENSIONS as $oldExt) {
                if ($oldExt === $ext) continue;
                $oldKey = "avatars/{$user->getKey()}.{$oldExt}";
                if (Storage::disk('local')->exists($oldKey)) {
                    Storage::disk('local')->delete($oldKey);
                }
            }

            // Persist the public URL on the user so the next page render
            // uses <img src="/avatars/{id}.{ext}"> directly.
            $url = url("/avatars/{$user->getKey()}.{$ext}");
            if ($url !== $user->photo_url) {
                $user->forceFill(['photo_url' => $url])->saveQuietly();
            }

            return ['key' => $key, 'ext' => $ext];
        } catch (RuntimeException $e) {
            Log::debug('AvatarController: Telegram fetch failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::debug('AvatarController: failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function serve(string $key, string $ext): Response
    {
        $bytes = Storage::disk('local')->get($key);
        $mime = $this->mimeFor($ext);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    private function mimeFor(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'          => 'image/gif',
            default       => 'application/octet-stream',
        };
    }
}
