<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redirects the browser to the user's Telegram profile photo URL.
 *
 * This controller is the SINGLE source of truth for serving user avatars.
 * It does NOT download image bytes — instead, it asks Telegram's Bot API
 * for the photo's file_path (via getUserProfilePhotos + getFile) and
 * returns a 302 redirect to the public Telegram CDN URL:
 *
 *     https://api.telegram.org/file/bot{TOKEN}/{file_path}
 *
 * Why a redirect (and not server-side byte streaming)?
 *
 * The previous implementation downloaded the bytes server-side and
 * streamed them back. That worked for the Mini App (one user, one
 * avatar, cached for 5 min) but caused real problems in the admin
 * panel:
 *
 *   - Each admin page renders 25+ avatars simultaneously (users list,
 *     KYC queue, etc.). With byte streaming, every avatar = 1 Telegram
 *     API call to getUserProfilePhotos + 1 to getFile + 1 HTTP download.
 *     That's 75+ outbound requests on a single page load.
 *   - The throttle limit (30 req/min/IP) gets hit immediately, so
 *     remaining avatars return 429 and the <img onerror> fallback kicks in.
 *   - The 5-minute byte cache doesn't help when 25 DIFFERENT users are
 *     rendered for the first time.
 *
 * The redirect approach fixes all of this:
 *
 *   - Each request still calls getUserProfilePhotos + getFile ONCE per
 *     user, but the resulting CDN URL is cached for ~1 hour (Telegram's
 *     file_path TTL). So subsequent requests for the same user are
 *     instant — no Telegram API calls at all.
 *   - The browser fetches the image bytes directly from Telegram's CDN
 *     (parallel, no throttling on our side).
 *   - The "user has no photo" state is also cached (5 min) so we don't
 *     hammer Telegram's API for users we already know have no photo.
 *
 * Security trade-off (acknowledged):
 *
 * The redirect URL embeds the bot token. This is visible in:
 *   - DevTools → Network → /avatars/{id} → Location header
 *   - The image's effective src after the redirect
 *
 * To minimize leakage:
 *   - The route is rate-limited (throttle:avatars, 30 req/min/IP).
 *   - Referrer-Policy: no-referrer is set on the response so the
 *     token-bearing URL is NOT sent in the Referer header when the
 *     browser follows the redirect to api.telegram.org.
 *   - The route is publicly accessible (no auth) ONLY because <img src>
 *     tags don't always forward the session cookie. In practice, the
 *     only pages that emit these <img> tags are the admin panel and
 *     the Mini App, both of which require authentication to reach.
 *
 * This is the same approach used by the Mini App: when a user logs in
 * via SessionController::store(), their `users.photo_url` is refreshed
 * and the Mini App's avatarUrl() helper returns it DIRECTLY (skipping
 * this controller entirely on the fast path). The admin panel uses the
 * same helper, but for OTHER users whose `photo_url` may be stale or
 * missing — that's when this controller kicks in as the fallback.
 */
class AvatarController extends Controller
{
    /** Cache TTL for the Telegram CDN URL (~50 min; Telegram's file_path is valid for ~1h). */
    private const URL_CACHE_TTL_SECONDS = 3000;

    /** Cache TTL for the "user has no photo" / "API unreachable" state (5 min). */
    private const NO_PHOTO_CACHE_TTL_SECONDS = 300;

    /** Cache key marker for "no photo available". */
    private const NO_PHOTO_MARKER = '__NO_PHOTO__';

    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    public function show(int $userId): RedirectResponse
    {
        $user = User::find($userId);
        abort_unless($user, 404);

        // ─── Fast path: use a freshly-persisted photo_url from the user row ───
        // When the user logs in via the Mini App, SessionController calls
        // $user->refreshTelegramPhotoUrl($bot) which persists a fresh CDN URL
        // on the user row. If that URL is still within Telegram's ~1h TTL
        // window, we can skip the Bot API calls entirely and redirect
        // straight to it.
        $freshUrl = $this->freshPersistedUrl($user);
        if ($freshUrl !== null) {
            return $this->redirectResponse($freshUrl);
        }

        // ─── Cached URL path ───
        // Check the cache for a previously-resolved URL. The cache holds
        // either the Telegram CDN URL (string) or the NO_PHOTO_MARKER
        // constant when we previously determined the user has no photo.
        $cacheKey = "avatar:url:{$userId}";
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            if ($cached === self::NO_PHOTO_MARKER) {
                // We already asked Telegram and the user had no photo.
                // Let the <img onerror> in the layout show the initial-letter
                // fallback. Cache the negative result so we don't re-ask
                // Telegram on every page load.
                abort(404);
            }
            return $this->redirectResponse($cached);
        }

        // ─── Cold path: ask Telegram for the photo URL ───
        $telegramUserId = (int) $user->telegram_user_id;
        if ($telegramUserId <= 0) {
            // No Telegram ID on file — the user can't possibly have a photo.
            // Cache the negative for the short TTL so we don't keep checking.
            Cache::put($cacheKey, self::NO_PHOTO_MARKER, self::NO_PHOTO_CACHE_TTL_SECONDS);
            abort(404);
        }

        try {
            $url = $this->botClient->getLatestUserProfilePhotoUrl($telegramUserId);
        } catch (\Throwable $e) {
            Log::debug('AvatarController: Telegram getUserProfilePhotos/getFile failed', [
                'user_id' => $user->getKey(),
                'telegram_user_id' => $telegramUserId,
                'exception' => $e->getMessage(),
            ]);
            $url = null;
        }

        if (! is_string($url) || $url === '') {
            // User has no Telegram profile photo, OR the Bot API was
            // unreachable. Cache the negative for 5 min so we don't
            // hammer Telegram on every page load.
            Cache::put($cacheKey, self::NO_PHOTO_MARKER, self::NO_PHOTO_CACHE_TTL_SECONDS);
            abort(404);
        }

        // Persist the URL on the user row so subsequent admin/mini-app
        // renders use the fast path without hitting this controller.
        // This is best-effort — failures here don't break the redirect.
        try {
            $user->forceFill([
                'photo_url' => $url,
                'updated_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            Log::debug('AvatarController: could not persist photo_url', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        // Cache the URL for the URL TTL so the next request for the
        // same user is an instant cache hit.
        Cache::put($cacheKey, $url, self::URL_CACHE_TTL_SECONDS);

        return $this->redirectResponse($url);
    }

    /**
     * If the user row has a `photo_url` that points at Telegram's CDN
     * AND was updated recently (well within Telegram's ~1h file_path TTL),
     * return it. Otherwise return null and let the controller fall
     * through to the Bot API call.
     *
     * Window is 45 min — gives a 15-min safety margin before Telegram's
     * 1h TTL would expire the file_path.
     */
    private function freshPersistedUrl(User $user): ?string
    {
        $url = $user->photo_url ?? null;
        if (! is_string($url) || $url === '' || ! str_contains($url, 'api.telegram.org/file/bot')) {
            return null;
        }

        $updated = $user->updated_at ?? null;
        if ($updated === null) {
            return null;
        }

        try {
            if ($updated->diffInMinutes(now()) >= 45) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $url;
    }

    /**
     * Build the 302 redirect. The Referrer-Policy header is set to
     * `no-referrer` so the bot-token-bearing URL is NOT leaked via
     * the Referer header when the browser follows the redirect to
     * api.telegram.org.
     *
     * Cache-Control is set to private + 5 min so the BROWSER doesn't
     * re-hit this controller for the same user within 5 min. (The
     * server-side cache handles the URL itself, but reducing
     * round-trips is still nice.)
     */
    private function redirectResponse(string $url): RedirectResponse
    {
        return redirect($url, 302)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, max-age=300')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
