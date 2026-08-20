@php($isFa = app()->isLocale('fa'))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isFa ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/ads-platform-192.svg" type="image/svg+xml">
    <title>{{ $isFa ? 'ورود مدیر' : 'Admin sign in' }} — {{ __('ui.brand') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="auth-shell">
    <section class="auth-panel">
        <div class="brand-lockup"><span class="brand-mark"><x-icon name="send" /></span><span class="brand-copy"><strong>{{ __('ui.brand') }}</strong><small>{{ $isFa ? 'مرکز عملیات' : 'Operations center' }}</small></span></div>
        <h1 class="auth-title">{{ $isFa ? 'ورود امن مدیر' : 'Secure admin sign in' }}</h1>
        <p class="auth-lead">{{ $isFa ? 'برای ادامه اطلاعات حساب مدیریتی خود را وارد کنید.' : 'Enter your administrator credentials to continue.' }}</p>
        <x-flash />
        <form class="form-grid" style="margin-top:18px" action="{{ \Illuminate\Support\Facades\Route::has('admin.login.store') ? route('admin.login.store') : '#' }}" method="post" data-loading-form>
            @csrf
            <div class="field"><label class="field-label required" for="email">{{ $isFa ? 'ایمیل' : 'Email' }}</label><input class="input ltr" id="email" name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}"></div>
            <div class="field"><label class="field-label required" for="password">{{ $isFa ? 'رمز عبور' : 'Password' }}</label><input class="input ltr" id="password" name="password" type="password" autocomplete="current-password" required></div>
            <label class="checkbox"><input type="checkbox" name="remember" value="1"><span>{{ $isFa ? 'در این دستگاه امن بمانم' : 'Keep me signed in on this secure device' }}</span></label>
            <button class="btn btn-primary btn-block" type="submit"><x-icon name="lock" />{{ $isFa ? 'ورود به پنل' : 'Sign in' }}</button>
        </form>
        <p class="muted" style="margin:18px 0 0;text-align:center;font-size:12px">{{ $isFa ? 'ورودها و عملیات حساس در گزارش فعالیت ثبت می‌شوند.' : 'Sign-ins and sensitive actions are audit logged.' }}</p>
    </section>
</main>
</body>
</html>
