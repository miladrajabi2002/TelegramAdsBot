@php($isFa = app()->isLocale('fa'))
@php($botUsername = config('services.telegram.bot_username'))
@php($botLink = $botUsername ? 'https://t.me/'.$botUsername.'?start=start' : null)
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isFa ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/ads-platform-192.svg" type="image/svg+xml">
    <title>{{ $isFa ? 'در حال ورود…' : 'Loading…' }} — {{ __('ui.brand') }}</title>
    <style>
        /* Tiny inline critical CSS so the loader paints before Vite bundle
           finishes downloading — this keeps the entry perceptually instant. */
        body { margin: 0; background: #f5f8fc; color: #17202a;
               font-family: 'Manrope Variable', system-ui, sans-serif; }
        html[lang="fa"] body { font-family: 'Vazirmatn Variable', system-ui, sans-serif; }
        .app-loader-shell {
            position: fixed; inset: 0;
            display: grid; place-items: center;
            background: radial-gradient(120% 80% at 50% 0%, #ffffff 0%, #f5f8fc 100%);
        }
        .app-loader-card { text-align: center; padding: 32px; max-width: 360px; }
        .app-loader-mark {
            width: 64px; height: 64px; margin: 0 auto 18px;
            border-radius: 18px;
            background: linear-gradient(135deg, #0b74b8 0%, #229ed9 100%);
            display: grid; place-items: center;
            color: #fff; box-shadow: 0 14px 36px rgba(11, 116, 184, 0.32);
            animation: app-loader-pulse 1.8s ease-in-out infinite;
        }
        @keyframes app-loader-pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 14px 36px rgba(11, 116, 184, 0.32); }
            50%      { transform: scale(0.96); box-shadow: 0 8px 24px rgba(11, 116, 184, 0.20); }
        }
        .app-loader-bar {
            width: 140px; height: 4px; margin: 18px auto 14px;
            background: #d7e1ea; border-radius: 999px; overflow: hidden;
            position: relative;
        }
        .app-loader-bar::after {
            content: ''; position: absolute; inset: 0;
            width: 40%; border-radius: inherit;
            background: linear-gradient(90deg, #0b74b8, #229ed9);
            animation: app-loader-slide 1.2s ease-in-out infinite;
        }
        @keyframes app-loader-slide {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(360%); }
        }
        .app-loader-title { font-size: 16px; font-weight: 700; margin: 0 0 6px; }
        .app-loader-sub   { font-size: 12px; color: #5c6675; margin: 0; }
        .app-loader-error { margin-top: 18px; }
        .app-loader-error[hidden] { display: none; }
    </style>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="app-loader-shell">
    <section class="app-loader-card" aria-live="polite">
        <div class="app-loader-mark">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m22 2-7 20-4-9-9-4Z"/>
                <path d="M22 2 11 13"/>
            </svg>
        </div>
        <h1 class="app-loader-title">{{ $isFa ? 'در حال ورود' : 'Loading' }}</h1>
        <p class="app-loader-sub">{{ $isFa ? 'یک لحظه، در حال آماده‌سازی صفحه' : 'Just a moment, preparing your space' }}</p>
        <div class="app-loader-bar" aria-hidden="true"></div>

        <form
            action="{{ \Illuminate\Support\Facades\Route::has('app.session.store') ? route('app.session.store') : '#' }}"
            method="post"
            data-miniapp-session
            data-label-connect="{{ $isFa ? 'در حال ورود…' : 'Loading…' }}"
            data-label-retry="{{ $isFa ? 'تلاش دوباره' : 'Retry sign-in' }}"
            hidden
        >
            @csrf
            <input type="hidden" name="init_data" value="">
            <input type="hidden" name="init_data_unsafe" value="">
            <input type="hidden" name="token" value="{{ request()->query('t', '') }}">
        </form>

        <div class="notice notice-danger app-loader-error" data-session-error hidden>
            <x-icon name="warning" />
            <div>
                <p>{{ $isFa ? 'اتصال ناموفق بود.' : 'Could not connect.' }}</p>
                <p class="muted" style="margin-top:6px;font-size:12px" data-session-error-hint></p>
                <div class="stack-sm" style="margin-top:10px">
                    @if($botLink)
                        <a class="btn btn-primary btn-block" href="tg://resolve?domain={{ $botUsername }}&start=start" data-telegram-redirect>
                            <x-icon name="send" />
                            <span>{{ $isFa ? 'ورود از تلگرام' : 'Open via Telegram' }}</span>
                        </a>
                        <a class="btn btn-secondary btn-block" href="{{ $botLink }}" data-telegram-redirect-fallback>
                            <x-icon name="send" />
                            <span>{{ $isFa ? 'باز کردن ربات در تلگرام' : 'Open bot in Telegram' }}</span>
                        </a>
                    @else
                        <button class="btn btn-secondary btn-block" type="button" data-session-retry>
                            <x-icon name="refresh" />
                            <span>{{ $isFa ? 'تلاش دوباره' : 'Retry sign-in' }}</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </section>
</main>
</body>
</html>
