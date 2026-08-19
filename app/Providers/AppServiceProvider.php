<?php

namespace App\Providers;

use App\Enums\KycStatus;
use App\Models\KycApplication;
use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\LiveZarinPayGateway;
use App\Services\Payments\MockZarinPayGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ZarinPayGateway::class, function ($app): ZarinPayGateway {
            return config('services.zarinpay.mock')
                ? new MockZarinPayGateway
                : $app->make(LiveZarinPayGateway::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('miniapp-session', fn (Request $request) => Limit::perMinute(15)->by($request->ip()));
        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute(8)->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('payment-callback', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(180)->by($request->ip()));
        // Per-user throttle for the campaign-creation channel search
        // endpoint so one user can't hammer Telegram's getChat API.
        // Default cap: 30 requests per minute per user.
        RateLimiter::for('miniapp-channel-search', fn (Request $request) => Limit::perMinute(
            (int) config('ads-platform.channel_search_per_minute', 30)
        )->by((string) ($request->user()?->getKey() ?? $request->ip())));

        // Avatar endpoint — fetches+cache the Telegram profile photo. The
        // first hit does the real Telegram API work; subsequent hits are
        // served from disk. Cap 30 req/min/IP so a misbehaving <img> reloader
        // can't DoS the Telegram bot API.
        RateLimiter::for('avatars', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Cache the pending KYC count for 60 seconds — previously this COUNT(*)
        // query fired on every single admin page render (orders, users, audit,
        // broadcasts, reports, settings, transactions, …). Now it fires at
        // most once a minute regardless of how many pages the admin visits.
        //
        // Cache is invalidated by KycService after any status change.
        View::composer('layouts.admin', function ($view): void {
            $view->with('currentAdmin', auth('admin')->user());
            $view->with('pendingKycCount', Cache::remember(
                'admin:pending-kyc-count',
                60,
                fn (): int => KycApplication::query()
                    ->whereIn('status', [KycStatus::Submitted, KycStatus::UnderReview])
                    ->count(),
            ));
        });

        // ─── Avatar URL helper ─────────────────────────────────────────
        // Returns a URL that ALWAYS works for a user's profile photo,
        // regardless of whether `users.photo_url` is empty, stale, or
        // points at an expired Telegram CDN link.
        //
        // How it works:
        //   - If photo_url already points at Telegram's CDN AND was updated
        //     in the last 30 minutes, return it as-is (fast path).
        //   - Otherwise, return the public /avatars/{id} route, which
        //     transparently refreshes the URL via AvatarController.
        //
        // Usage in Blade:
        //   <img src="{{ avatar_url($user) }}">
        //   <img src="{{ avatar_url($user?->id) }}">
        Blade::directive('avatarUrl', function ($expression) {
            return "<?php echo e(\\App\\Providers\\AppServiceProvider::avatarUrl({$expression})); ?>";
        });
    }

    /**
     * Resolve a usable avatar URL for the given user (or user id).
     *
     * Always returns the public /avatars/{id} route — which streams the
     * photo bytes server-side. This works in BOTH the Mini App (Telegram
     * WebView) AND the admin panel (regular browser), because:
     *
     *   - The browser only talks to OUR server (same origin as the page).
     *   - The bot token is never exposed in the HTML.
     *   - No CORS or mixed-content issues.
     *   - The AvatarController caches the downloaded bytes for 5 minutes
     *     so repeated requests are fast.
     *
     * Previously this had a "fast path" that returned the Telegram CDN
     * URL directly when it was fresh (< 30 min). But that URL contains
     * the bot token (visible in DevTools / page source) and sometimes
     * fails to load in regular browsers due to network restrictions on
     * api.telegram.org. The server-side stream fixes both issues.
     *
     * @param  mixed  $user  A User model, an array with id+photo_url,
     *                       or a numeric user id.
     */
    public static function avatarUrl(mixed $user): string
    {
        $id = null;

        if (is_object($user)) {
            $id = $user->id ?? null;
        } elseif (is_array($user)) {
            $id = $user['id'] ?? null;
        } else {
            $id = $user;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        if (Route::has('avatar.show')) {
            return route('avatar.show', ['userId' => $id]);
        }

        return url('/avatars/'.$id);
    }
}
