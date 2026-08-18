<?php

namespace App\Providers;

use App\Enums\KycStatus;
use App\Models\KycApplication;
use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\LiveZarinPayGateway;
use App\Services\Payments\MockZarinPayGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
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
    }
}
