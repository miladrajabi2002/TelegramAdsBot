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
    <title>{{ $isFa ? 'ورود امن' : 'Secure sign in' }} — {{ __('ui.brand') }}</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="auth-shell">
    <section class="auth-panel" aria-live="polite">
        <div class="brand-lockup"><span class="brand-mark"><x-icon name="send" /></span><span class="brand-copy"><strong>{{ __('ui.brand') }}</strong><small>{{ $isFa ? 'تبلیغات Telegram، ساده و شفاف' : 'Telegram ads, made clear' }}</small></span></div>
        <div style="margin-top:24px"><span class="quick-icon"><x-icon name="lock" /></span></div>
        <h1 class="auth-title">{{ $isFa ? 'در حال اتصال امن' : 'Connecting securely' }}</h1>
        <p class="auth-lead">{{ $isFa ? 'هویت Telegram شما به‌صورت رمزنگاری‌شده بررسی می‌شود. این فرآیند معمولاً چند لحظه طول می‌کشد.' : 'Your Telegram identity is being verified securely. This normally takes only a moment.' }}</p>

        <form
            action="{{ \Illuminate\Support\Facades\Route::has('app.session.store') ? route('app.session.store') : '#' }}"
            method="post"
            data-miniapp-session
            data-label-connect="{{ $isFa ? 'در حال اتصال…' : 'Connecting…' }}"
            data-label-retry="{{ $isFa ? 'تلاش دوباره' : 'Retry sign-in' }}"
        >
            @csrf
            <input type="hidden" name="init_data" value="">
            <button class="btn btn-primary btn-block" type="submit" disabled><x-icon name="send" /><span data-session-button-label>{{ $isFa ? 'در حال اتصال…' : 'Connecting…' }}</span></button>
        </form>

        <div
            class="notice notice-danger"
            style="margin-top:14px"
            data-session-error
            data-unavailable-hint="{{ $isFa ? 'دکمه «ورود از تلگرام» را بزنید؛ تلگرام باز می‌شود و دکمه «📱 ورود به مینی‌اپ» را می‌بینید. روی آن بزنید تا مینی‌اپ به‌صورت امن باز شود.' : 'Tap “Open via Telegram” — you will see the “📱 Open Mini App” button. Tap it to launch the Mini App securely.' }}"
            hidden
        >
            <x-icon name="warning" />
            <div>
                <p>{{ $isFa ? 'ورود مستقیم امکان‌نیست. لطفاً از داخل تلگرام وارد شوید.' : 'Direct sign-in is not available. Please enter via Telegram.' }}</p>
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

        <p class="muted" style="margin:16px 0 0;text-align:center;font-size:12px">{{ $isFa ? 'ما رمز Telegram یا کد ورود شما را دریافت نمی‌کنیم.' : 'We never receive your Telegram password or login code.' }}</p>
    </section>
</main>
</body>
</html>
