<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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

    public function store(Request $request, TelegramInitDataValidator $validator): JsonResponse
    {
        $validated = $request->validate(['init_data' => ['required', 'string', 'max:16384']]);
        $data = $validator->validate($validated['init_data']);
        $telegramUser = $data['user'] ?? null;

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
        if (! $user->exists) {
            $user->locale = $locale;
        }
        $user->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['redirect' => route('app.home')]);
    }

    public function language(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(['fa', 'en'])]]);
        $request->user()->update(['locale' => $validated['locale']]);

        return back();
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['fa', 'en'], true), 404);
        $request->user()->update(['locale' => $locale]);

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
