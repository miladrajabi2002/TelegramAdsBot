@php($isFa = app()->isLocale('fa'))
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
            data-unavailable-hint="{{ $isFa ? 'دکمه «تلاش دوباره» را بزنید؛ اگر مشکل پابرجا بود، مینی‌اپ را از داخل تلگرام (دکمه منوی ربات) باز کنید و مطمئن شوید دامنه در BotFather ثبت شده.' : 'Tap “Retry sign-in”; if it still fails, open the Mini App from the bot menu button and verify the domain is registered with BotFather.' }}"
            hidden
        >
            <x-icon name="warning" />
            <div>
                <p>{{ $isFa ? 'اطلاعات ورود Telegram در دسترس نیست. مینی‌اپ را از دکمه ربات باز کنید و دوباره تلاش کنید.' : 'Telegram sign-in data is unavailable. Open the Mini App via the bot’s “Enter app” button, then retry.' }}</p>
                <p class="muted" style="margin-top:6px;font-size:12px" data-session-error-hint></p>
                <button class="btn btn-secondary btn-sm" type="button" data-session-retry style="margin-top:10px">
                    <x-icon name="refresh" />
                    <span>{{ $isFa ? 'تلاش دوباره' : 'Retry sign-in' }}</span>
                </button>
            </div>
        </div>

        <p class="muted" style="margin:16px 0 0;text-align:center;font-size:12px">{{ $isFa ? 'ما رمز Telegram یا کد ورود شما را دریافت نمی‌کنیم.' : 'We never receive your Telegram password or login code.' }}</p>
    </section>
</main>
</body>
</html>
