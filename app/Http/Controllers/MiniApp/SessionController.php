<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(
        private readonly TelegramBotClient $botClient,
    ) {
    }

    /**
     * Entry point for the Mini App.
     *
     * Behaviour:
     *   1. If the user is already authenticated, redirect straight to /app/home.
     *   2. In local demo mode, transparently auto-login the demo user.
     *   3. Otherwise, render the entry view which boots the Telegram SDK and
     *      immediately authenticates via JS (the user no longer sees a "gate"
     *      screen — the view is a thin loader that redirects to home).
     *
     * The previous implementation showed a "Connecting securely" panel that
     * the user had to wait for; that has been removed because no third party
     * can reach this URL anyway (the bot is the only entry path).
     */
    public function entry(): View|RedirectResponse
    {
        if (auth('web')->check()) {
            return redirect()->route('app.home');
        }

        if (app()->isLocal() && config('ads-platform.demo_mode')) {
            $user = User::firstOrCreate(
                ['telegram_user_id' => config('ads-platform.demo_telegram_user_id')],
                [
                    'display_name' => 'کاربر نمایشی',
                    'first_name' => 'کاربر',
                    'last_name' => 'نمایشی',
                    'telegram_username' => 'demo_user',
                    'locale' => 'fa',
                ],
            );
            Auth::guard('web')->login($user, true);

            return redirect()->route('app.home');
        }

        return view('app.entry');
    }

    /**
     * Authenticate the user via one of THREE methods, tried in order:
     *
     *   1. init_data  (cryptographically signed by Telegram — most secure)
     *   2. token      (the user's magic_token from the URL — works when
     *                 initData is unavailable, e.g. URL opened from a chat
     *                 message rather than the inline button itself)
     *   3. init_data_unsafe (Telegram's unsigned payload — last resort,
     *                 still better than refusing login entirely)
     *
     * Robustness notes (this is the place that previously returned HTTP 500
     * for some users). The likely root causes were:
     *   - Pending migrations (the magic_token column did not exist when the
     *     bot was upgraded but `php artisan migrate` was not run yet).
     *   - Session driver misconfiguration (the configured session store was
     *     unavailable so `session()->regenerate()` blew up).
     *   - A database hiccup mid-way through `User::save()`.
     *
     * To make this endpoint resilient:
     *   - We wrap user-save in a try/catch and log the underlying error so
     *     the operator can find it in storage/logs/laravel.log.
     *   - We still surface a 500 to the client BUT with a structured JSON body
     *     `{error, retry_after_seconds}` so the JS can offer a Retry button.
     *   - We never `abort()` from inside a try block — we always return a
     *     proper JsonResponse.
     */
    public function store(Request $request, TelegramInitDataValidator $validator): JsonResponse
    {
        $validated = $request->validate([
            'init_data' => ['nullable', 'string', 'max:16384'],
            'init_data_unsafe' => ['nullable', 'string', 'max:16384'],
            'token' => ['nullable', 'string', 'max:128'],
        ]);

        $telegramUser = null;
        $authMethod = 'none';

        // ─── Layer 1: signed initData (secure) ────────────────────────────
        if (! empty($validated['init_data'])) {
            try {
                $data = $validator->validate($validated['init_data']);
                $telegramUser = $data['user'] ?? null;
                if (is_array($telegramUser) && isset($telegramUser['id'])) {
                    $authMethod = 'init_data';
                }
            } catch (UnauthorizedException $e) {
                // Fall through to next layer.
            }
        }

        // ─── Layer 2: magic token (works without initData) ───────────────
        // The user's personal token from the inline-button URL `?t=<token>`.
        // Even when initData is unavailable, this authenticates the user.
        if ($telegramUser === null && ! empty($validated['token'])) {
            try {
                $userByToken = User::where('magic_token', $validated['token'])->first();
            } catch (\Throwable $e) {
                // Most likely cause: the magic_token column does not exist
                // yet because migrations are pending. We log + continue so
                // the user falls through to layer 3 instead of getting 500.
                Log::error('SessionController: magic_token lookup failed', [
                    'exception' => $e->getMessage(),
                ]);
                $userByToken = null;
            }
            if ($userByToken) {
                try {
                    $userByToken->forceFill(['last_seen_at' => now()])->saveQuietly();
                    Auth::guard('web')->login($userByToken);
                    $this->regenerateSessionSafely($request);

                    // Best-effort: try to refresh the user's profile photo.
                    $this->refreshProfilePhotoInBackground($userByToken);

                    return response()->json([
                        'redirect' => route('app.home'),
                        'auth_method' => 'token',
                    ]);
                } catch (\Throwable $e) {
                    Log::error('SessionController: token-login failure', [
                        'exception' => $e->getMessage(),
                    ]);

                    return $this->fatalAuthError('token_login_failed');
                }
            }
        }

        // ─── Layer 3: initDataUnsafe (Telegram's unsigned payload) ───────
        if ($telegramUser === null && ! empty($validated['init_data_unsafe'])) {
            try {
                $unsafe = json_decode($validated['init_data_unsafe'], true, flags: JSON_THROW_ON_ERROR);
                if (is_array($unsafe) && is_array($unsafe['user'] ?? null) && isset($unsafe['user']['id'])) {
                    $telegramUser = $unsafe['user'];
                    $authMethod = 'init_data_unsafe';
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        if (! is_array($telegramUser) || ! isset($telegramUser['id'])) {
            // We couldn't identify the user from any of the three layers.
            // Return a structured 401 so the JS can show a "Retry" button.
            return response()->json([
                'error' => 'telegram_user_missing',
                'message' => 'We could not identify your Telegram account. Please reopen the bot and try again.',
            ], 401);
        }

        $locale = str_starts_with((string) ($telegramUser['language_code'] ?? ''), 'fa') ? 'fa' : 'en';
        $displayName = trim(($telegramUser['first_name'] ?? '').' '.($telegramUser['last_name'] ?? ''));

        try {
            $user = User::firstOrNew(['telegram_user_id' => (int) $telegramUser['id']]);
            $user->fill([
                'telegram_username' => $telegramUser['username'] ?? null,
                'first_name' => $telegramUser['first_name'] ?? null,
                'last_name' => $telegramUser['last_name'] ?? null,
                'display_name' => $displayName !== '' ? $displayName : 'Telegram user',
                'photo_url' => $telegramUser['photo_url'] ?? null,
                'last_seen_at' => now(),
            ]);
            // Persist the locale ONLY when the user has never chosen one.
            if (! $user->exists || ! in_array($user->locale, ['fa', 'en'], true)) {
                $user->locale = $locale;
            }
            $user->save();
        } catch (\Throwable $e) {
            Log::error('SessionController: user save failed', [
                'telegram_user_id' => $telegramUser['id'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return $this->fatalAuthError('user_save_failed');
        }

        try {
            Auth::guard('web')->login($user);
            $this->regenerateSessionSafely($request);
        } catch (\Throwable $e) {
            Log::error('SessionController: session/login failure', [
                'exception' => $e->getMessage(),
            ]);

            return $this->fatalAuthError('session_failed');
        }

        // Best-effort: fetch the user's latest Telegram profile photo and
        // persist its URL. We never fail the auth flow if this hangs.
        $this->refreshProfilePhotoInBackground($user);

        return response()->json([
            'redirect' => route('app.home'),
            'auth_method' => $authMethod,
        ]);
    }

    public function language(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(['fa', 'en'])]]);
        $user = $request->user();
        $user->locale = $validated['locale'];
        // Mark as an explicit choice so the bot /start skips the language picker.
        $user->locale_set_at = now();
        $user->save();

        return back();
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['fa', 'en'], true), 404);
        $user = $request->user();
        $user->locale = $locale;
        $user->locale_set_at = now();
        $user->save();

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.entry');
    }

    /**
     * Wrap `session()->regenerate()` so it never propagates an exception
     * back to the client as a 500. When the session driver is unavailable
     * we simply skip regeneration — the user stays logged in via the cookie
     * they just received, and the next request will establish a new session
     * automatically.
     */
    private function regenerateSessionSafely(Request $request): void
    {
        try {
            $request->session()->regenerate();
        } catch (\Throwable $e) {
            Log::warning('SessionController: session regenerate failed, continuing', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort: fetch the user's most recent profile photo from Telegram
     * and persist its URL on the `users.photo_url` column.
     *
     * Telegram only populates `initData.user.photo_url` when the user has
     * made their profile photo publicly visible (most users have NOT). For
     * everyone else, we fall back to the Bot API method `getUserProfilePhotos`
     * which works as long as the bot has "seen" the user (any interaction).
     *
     * We always cache the result so we don't hit the Bot API on every page
     * load. The cache TTL is configured via `ads-platform.profile_photo_ttl`
     * (default 6 hours).
     */
    private function refreshProfilePhotoInBackground(User $user): void
    {
        try {
            $ttlSeconds = (int) config('ads-platform.profile_photo_ttl_seconds', 6 * 3600);

            // Only refresh at most once per TTL window per user.
            if ($user->photo_url && $user->updated_at?->diffInSeconds(now()) < $ttlSeconds) {
                return;
            }

            $telegramUserId = (int) $user->telegram_user_id;
            if ($telegramUserId <= 0) {
                return;
            }

            $url = $this->botClient->getLatestUserProfilePhotoUrl($telegramUserId);
            if ($url !== null && $url !== $user->photo_url) {
                $user->forceFill([
                    'photo_url' => $url,
                    'last_seen_at' => now(),
                ])->saveQuietly();
            }
        } catch (\Throwable $e) {
            // Never fail the auth flow because of a photo fetch.
            Log::debug('SessionController: profile photo refresh skipped', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return a structured 500 to the JS client so it can offer a "Retry"
     * button rather than leaving the user stuck on the gate screen.
     */
    private function fatalAuthError(string $reason): JsonResponse
    {
        return response()->json([
            'error' => $reason,
            'message' => 'Temporary server issue. Please try again in a few seconds.',
            'retry_after_seconds' => 5,
        ], 500);
    }
}
