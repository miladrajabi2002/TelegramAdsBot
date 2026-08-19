<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Streams the user's Telegram profile photo via a 302 redirect.
 *
 * Why this design (instead of downloading + caching the bytes):
 *   - Telegram's getFile() returns a one-hour CDN URL that already includes
 *     the bot token (https://api.telegram.org/file/bot<TOKEN>/<path>). We
 *     store that URL directly on `users.photo_url` and let the browser load
 *     the bytes from Telegram's CDN on demand. No disk, no queue, no token
 *     leak through our own server.
 *   - This route is kept as a backward-compatible fallback: any stale
 *     `<img src="/avatars/{id}">` tag (e.g. cached HTML from before the
 *     refactor) still resolves correctly, because we transparently refresh
 *     the URL and redirect to it.
 *
 * Public (no auth) so <img src> tags work without the session cookie.
 * Rate-limited by the `avatars` limiter (30 req/min/IP).
 */
class AvatarController extends Controller
{
    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    public function show(int $userId): RedirectResponse
    {
        $user = User::find($userId);
        abort_unless($user, 404);

        $url = $this->resolveUrl($user);

        if ($url === null) {
            // No photo (or Telegram API unreachable) — let the <img onerror>
            // in the layout fall back to the initial-letter avatar.
            abort(404);
        }

        return redirect()->away($url, 302)
            ->header('Cache-Control', 'public, max-age=300')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Resolve a usable Telegram CDN URL for the user's profile photo.
     *
     * Strategy (in order):
     *   1. If `users.photo_url` already points at Telegram's CDN AND was
     *      updated less than 30 minutes ago, use it as-is (fast path —
     *      no Bot API call needed).
     *   2. Otherwise (empty, stale, or pointing at a non-Telegram URL),
     *      call the Bot API right now via `refreshTelegramPhotoUrl()` and
     *      read back the freshly-persisted URL.
     *
     * Returns null when the user has no profile photo or every API call
     * failed — caller should return 404 so the <img onerror> fallback
     * in the layout shows the initial-letter avatar instead.
     */
    private function resolveUrl(User $user): ?string
    {
        // Fast path: fresh Telegram CDN URL (less than 30 minutes old).
        if (is_string($user->photo_url) && $user->photo_url !== '' && str_contains($user->photo_url, 'api.telegram.org/file/bot')) {
            $updated = $user->updated_at ?? now();
            try {
                if ($updated->diffInMinutes(now()) < 30) {
                    return $user->photo_url;
                }
            } catch (\Throwable) {
                // Fall through to a fresh fetch.
            }
        }

        // Stale URL or no URL at all → fetch a fresh one from Telegram.
        try {
            $ok = $user->refreshTelegramPhotoUrl($this->botClient);
        } catch (\Throwable $e) {
            Log::debug('AvatarController: Telegram refresh failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        if (! $ok) {
            return null;
        }

        // Only return the URL when it actually points at Telegram's CDN.
        // Stale internal URLs (e.g. `/avatars/{id}`) would cause a redirect loop.
        if (is_string($user->photo_url) && $user->photo_url !== '' && str_contains($user->photo_url, 'api.telegram.org/file/bot')) {
            return $user->photo_url;
        }

        return null;
    }
}
