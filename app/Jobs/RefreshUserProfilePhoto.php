<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Asynchronously download the user's latest Telegram profile photo, store
 * it locally (so we don't have to keep hitting the Bot API on every page
 * render and so the URL doesn't expire — Telegram's bot-file URLs are
 * only valid for ~1 hour after getFile), and persist a permanent URL on
 * `users.photo_url`.
 *
 * The Mini App then renders `photo_url` directly. If anything fails
 * (user has no photo, network glitch, storage unwritable) we silently
 * leave the previous value in place — the user just keeps their
 * initial-letter avatar.
 */
class RefreshUserProfilePhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 12;

    public function __construct(
        public int $userId,
    ) {
    }

    public function handle(TelegramBotClient $bot): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $telegramUserId = (int) $user->telegram_user_id;
        if ($telegramUserId <= 0) {
            return;
        }

        // Throttle: don't re-fetch more than once per TTL window.
        $ttlSeconds = (int) config('ads-platform.profile_photo_ttl_seconds', 6 * 3600);
        if ($user->photo_url && $user->updated_at?->diffInSeconds(now()) < $ttlSeconds) {
            return;
        }

        try {
            $photos = $bot->getUserProfilePhotos($telegramUserId, 1);
            if (! isset($photos['photos'][0]) || ! is_array($photos['photos'][0])) {
                // User has no profile photo — leave whatever we had.
                return;
            }
            $sizes = $photos['photos'][0];
            /** @var array<string, mixed> $largest */
            $largest = end($sizes);
            $fileId = $largest['file_id'] ?? null;
            if (! is_string($fileId) || $fileId === '') {
                return;
            }

            $file = $bot->getFile($fileId);
            $filePath = $file['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '') {
                return;
            }

            $token = (string) config('services.telegram.bot_token');
            if ($token === '') {
                return;
            }
            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

            // Download the photo bytes once. We use a short timeout so a slow
            // Telegram CDN doesn't hold the worker for too long.
            $response = Http::timeout(6)->retry(1, 100)->get($downloadUrl);
            if (! $response->ok()) {
                return;
            }
            $bytes = $response->body();
            if ($bytes === '' || strlen($bytes) > 8 * 1024 * 1024) {
                return;
            }

            // Detect extension from the file_path (Telegram returns .jpg
            // most of the time; fall back to .jpg otherwise).
            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
            if ($ext === 'jpeg') $ext = 'jpg';
            // Store under a private disk so we don't require `storage:link`
            // to be run. We serve via a route + controller.
            $key = "avatars/{$user->getKey()}.{$ext}";

            $disk = Storage::disk('local');
            $disk->put($key, $bytes);

            // Build a permanent URL via our own route (works without
            // storage:link because the controller streams the file).
            $url = url("/avatars/{$user->getKey()}.{$ext}");

            // Clean up any previous avatar files for this user with a
            // different extension (so we don't end up with both .jpg and
            // .webp after they update their photo).
            foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $oldExt) {
                if ($oldExt === $ext) continue;
                $oldKey = "avatars/{$user->getKey()}.{$oldExt}";
                if ($disk->exists($oldKey)) {
                    $disk->delete($oldKey);
                }
            }

            if ($url !== $user->photo_url) {
                $user->forceFill([
                    'photo_url' => $url,
                ])->saveQuietly();
            }
        } catch (RuntimeException $e) {
            // Telegram API error — log at debug, don't retry.
            Log::debug('RefreshUserProfilePhoto: bot call failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('RefreshUserProfilePhoto: failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
