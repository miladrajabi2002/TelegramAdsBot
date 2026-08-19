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
        // Two paths:
        //   1. FAST PATH — if `photo_url` is a Telegram CDN URL AND was
        //      updated within the last 30 minutes, return it directly.
        //      This is what the Mini App normally uses (SessionController
        //      refreshes `photo_url` on every login).
        //   2. SLOW PATH — return the public /avatars/{id} route, which
        //      asks Telegram's Bot API on demand and 302-redirects the
        //      browser to the CDN URL. The resolved URL is cached
        //      server-side for ~1 hour.
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
     * Two paths:
     *
     *   1. FAST PATH — if `$user` is a User model (or an array with
     *      both `id` and `photo_url`) AND `photo_url` points at
     *      Telegram's CDN AND was updated within the last 30 minutes,
     *      return `photo_url` directly. This is the path the Mini App
     *      normally takes (SessionController refreshes `photo_url` on
     *      every login), so the avatar renders with ZERO extra HTTP
     *      round-trips to our own /avatars/{id} endpoint.
     *
     *   2. SLOW PATH — return the public /avatars/{id} route. The
     *      AvatarController will ask Telegram's Bot API for the file_path
     *      on demand, 302-redirect the browser to the Telegram CDN URL,
     *      and cache that URL server-side for ~1 hour so subsequent
     *      requests for the same user are instant. The admin panel
     *      takes this path for users whose `photo_url` is stale or
     *      missing.
     *
     * This works in BOTH the Mini App (Telegram WebView) AND the admin
     * panel (regular browser) because the redirect target is Telegram's
     * public CDN endpoint, which Telegram's WebView can always reach
     * and most regular browsers can too.
     *
     * @param  mixed  $user  A User model, an array with id+photo_url,
     *                       or a numeric user id.
     */
    public static function avatarUrl(mixed $user): string
    {
        $id = null;
        $photoUrl = null;
        $updatedAt = null;

        if (is_object($user)) {
            $id = $user->id ?? null;
            $photoUrl = $user->photo_url ?? null;
            $updatedAt = $user->updated_at ?? null;
        } elseif (is_array($user)) {
            $id = $user['id'] ?? null;
            $photoUrl = $user['photo_url'] ?? null;
            $updatedAt = $user['updated_at'] ?? null;
        } else {
            $id = $user;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        // ─── FAST PATH ───────────────────────────────────────────────
        // Use the persisted `photo_url` when it's a fresh Telegram CDN URL.
        // 30 min window is well within Telegram's ~1h file_path TTL, so
        // the URL is still valid. Anything older than 30 min falls through
        // to the slow path so we re-resolve via the Bot API.
        if (is_string($photoUrl) && $photoUrl !== ''
            && str_contains($photoUrl, 'api.telegram.org/file/bot')) {
            $fresh = false;
            if ($updatedAt !== null) {
                try {
                    $updatedCarbon = $updatedAt instanceof \Illuminate\Support\Carbon
                        ? $updatedAt
                        : \Illuminate\Support\Carbon::parse($updatedAt);
                    $fresh = $updatedCarbon->diffInMinutes(now()) < 30;
                } catch (\Throwable) {
                    $fresh = false;
                }
            }
            if ($fresh) {
                return $photoUrl;
            }
        }

        // ─── SLOW PATH ───────────────────────────────────────────────
        if (Route::has('avatar.show')) {
            return route('avatar.show', ['userId' => $id]);
        }

        return url('/avatars/'.$id);
    }
}
