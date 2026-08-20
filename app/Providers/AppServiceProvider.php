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
    public function register(): void
    {
        $this->app->singleton(ZarinPayGateway::class, function ($app): ZarinPayGateway {
            return config('services.zarinpay.mock')
                ? new MockZarinPayGateway
                : $app->make(LiveZarinPayGateway::class);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('miniapp-session', fn (Request $request) => Limit::perMinute(15)->by($request->ip()));

        // Generic write protection for authenticated Mini App actions such as
        // ticket replies and campaign state changes. This primarily guards
        // accidental double taps/retries and basic scripted abuse.
        RateLimiter::for('miniapp-write', fn (Request $request) => Limit::perMinute(20)
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));

        // KYC accepts multiple large image uploads and performs encryption, so
        // it gets a stricter per-user limiter than ordinary Mini App writes.
        RateLimiter::for('kyc-submit', function (Request $request): array {
            $key = (string) ($request->user()?->getKey() ?? $request->ip());

            return [
                Limit::perMinute(4)->by('kyc-minute:'.$key),
                Limit::perHour(12)->by('kyc-hour:'.$key),
            ];
        });

        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute(8)->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('payment-callback', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(180)->by($request->ip()));
        RateLimiter::for('miniapp-channel-search', fn (Request $request) => Limit::perMinute(
            (int) config('ads-platform.channel_search_per_minute', 30)
        )->by((string) ($request->user()?->getKey() ?? $request->ip())));

        // Avatar requests are authenticated in AvatarController. A larger
        // allowance avoids breaking admin pages that legitimately render
        // dozens of avatars at once while still bounding abusive reloads.
        RateLimiter::for('avatars', function (Request $request): Limit {
            if (auth('admin')->check()) {
                $key = 'admin:'.auth('admin')->id();
            } elseif ($request->user()) {
                $key = 'user:'.$request->user()->getKey();
            } else {
                $key = 'ip:'.$request->ip();
            }

            return Limit::perMinute(120)->by($key);
        });

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

        // Always return our same-origin avatar proxy. Never return a Telegram
        // file URL directly: Telegram embeds the bot token in that URL.
        Blade::directive('avatarUrl', function ($expression) {
            return "<?php echo e(\\App\\Providers\\AppServiceProvider::avatarUrl({$expression})); ?>";
        });
    }

    /**
     * Resolve the local avatar-proxy URL for a user/model/id.
     *
     * The controller enforces access:
     *   - Mini App users may fetch only their own avatar.
     *   - Admin users may fetch any user's avatar.
     */
    public static function avatarUrl(mixed $user): string
    {
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
