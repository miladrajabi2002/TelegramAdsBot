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
     *   1. If `users.photo_url` already points at Telegram's CDN, use it as-is
     *      (the User model enforces a 30-minute freshness window elsewhere).
     *   2. Otherwise (empty or stale internal `/avatars/{id}` URL), call the
     *      Bot API right now via `refreshTelegramPhotoUrl()` and read back
     *      the freshly-persisted URL — but only accept it when it really
     *      points at Telegram's CDN, otherwise we'd redirect back into
     *      ourselves and create an infinite loop.
     *
     * Returns null when the user has no profile photo or every API call failed.
     */
    private function resolveUrl(User $user): ?string
    {
        if (is_string($user->photo_url) && str_contains($user->photo_url, 'api.telegram.org/file/bot')) {
            return $user->photo_url;
        }

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
        if (is_string($user->photo_url) && str_contains($user->photo_url, 'api.telegram.org/file/bot')) {
            return $user->photo_url;
        }

        return null;
    }
}
