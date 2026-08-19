<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Serves the user's Telegram profile photo as a streamed image.
 *
 * The controller downloads the photo bytes from Telegram's Bot API
 * (getUserProfilePhotos → getFile → download) and streams them back
 * to the browser. This is the ONLY path — there is no 302 redirect to
 * the Telegram CDN, because that URL embeds the bot token (visible in
 * DevTools / page source) and is sometimes blocked by network filters
 * on api.telegram.org when accessed from a regular browser (i.e. the
 * admin panel).
 *
 * The downloaded bytes are cached for 5 minutes so that multiple admin
 * pages rendering the same user's avatar do NOT trigger N Telegram
 * API calls. The browser also caches the response for 5 minutes
 * (Cache-Control: public, max-age=300, immutable).
 *
 * Public (no auth) so <img src> tags work without the session cookie.
 * Rate-limited by the `avatars` limiter (30 req/min/IP).
 *
 * This works in BOTH the Mini App (Telegram WebView) AND the admin
 * panel (regular browser) because the browser only ever talks to OUR
 * server (same origin as the page) — no CORS, no mixed-content, no
 * token leak.
 */
class AvatarController extends Controller
{
    /** Cache TTL for the downloaded photo bytes (5 minutes). */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    public function show(int $userId): Response
    {
        $user = User::find($userId);
        abort_unless($user, 404);

        // Try the cache first — multiple admin pages rendering the same
        // user's avatar should NOT trigger N Telegram API calls.
        $cacheKey = "avatar:bytes:{$userId}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['bytes'], $cached['mime'])) {
            return $this->streamResponse($cached['bytes'], $cached['mime']);
        }

        // Fetch fresh bytes from Telegram.
        $telegramUserId = (int) $user->telegram_user_id;
        if ($telegramUserId <= 0) {
            abort(404);
        }

        try {
            $photo = $this->botClient->downloadLatestUserProfilePhoto($telegramUserId);
        } catch (\Throwable $e) {
            Log::debug('AvatarController: Telegram download failed', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
            $photo = null;
        }

        if ($photo === null) {
            // No photo (or Telegram API unreachable) — let the <img onerror>
            // in the layout fall back to the initial-letter avatar.
            abort(404);
        }

        // Cache the bytes for a short window so the next admin page
        // load doesn't re-download from Telegram.
        Cache::put($cacheKey, $photo, self::CACHE_TTL_SECONDS);

        return $this->streamResponse($photo['bytes'], $photo['mime']);
    }

    /**
     * Build a streaming image response with cache headers.
     */
    private function streamResponse(string $bytes, string $mime): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'public, max-age=300, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
