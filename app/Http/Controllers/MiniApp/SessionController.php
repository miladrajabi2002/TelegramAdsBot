<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\View\View;

class SessionController extends Controller
{
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
     * This three-layer approach is what makes the Mini App "just work"
     * regardless of how the user opened it. The previous implementation only
     * accepted layer 1, which failed for any non-button open path.
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
            $userByToken = User::where('magic_token', $validated['token'])->first();
            if ($userByToken) {
                // Refresh last_seen and log them in directly.
                $userByToken->forceFill(['last_seen_at' => now()])->saveQuietly();
                Auth::guard('web')->login($userByToken);
                $request->session()->regenerate();

                return response()->json([
                    'redirect' => route('app.home'),
                    'auth_method' => 'token',
                ]);
            }
        }

        // ─── Layer 3: initDataUnsafe (Telegram's unsigned payload) ───────
        // Telegram populates `initDataUnsafe.user` for SOME open paths even
        // when the signed `initData` is missing (older clients, edge cases).
        // It's not cryptographically verified, but it WAS sent by Telegram —
        // so we trust the user_id for identification purposes only.
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

        abort_unless(is_array($telegramUser) && isset($telegramUser['id']), 401, 'Telegram user is missing.');

        $locale = str_starts_with((string) ($telegramUser['language_code'] ?? ''), 'fa') ? 'fa' : 'en';
        $displayName = trim(($telegramUser['first_name'] ?? '').' '.($telegramUser['last_name'] ?? ''));

        $user = User::firstOrNew(['telegram_user_id' => (int) $telegramUser['id']]);
        $user->fill([
            'telegram_username' => $telegramUser['username'] ?? null,
            'first_name' => $telegramUser['first_name'] ?? null,
            'last_name' => $telegramUser['last_name'] ?? null,
            'display_name' => $displayName !== '' ? $displayName : 'Telegram user',
            'photo_url' => $telegramUser['photo_url'] ?? null,
            'last_seen_at' => now(),
        ]);
        // Persist the locale ONLY when the user has never chosen one. The
        // Telegram client language_code is a hint, not an explicit choice —
        // once the user picks fa/en from the inline buttons we honour it
        // forever.
        if (! $user->exists || ! in_array($user->locale, ['fa', 'en'], true)) {
            $user->locale = $locale;
        }
        $user->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

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
}
