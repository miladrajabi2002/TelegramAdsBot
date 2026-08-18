<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="description" content="{{ $description }}">
    <title>{{ $title }} — {{ config('ads-platform.brand') }}</title>
    <link rel="icon" href="/icons/ads-platform-192.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#legal-content">{{ $locale === 'fa' ? 'رفتن به محتوا' : 'Skip to content' }}</a>
    <div class="mini-shell">
        <header class="mini-topbar">
            <div class="mini-topbar-inner">
                <a class="brand-lockup" href="{{ url('/app') }}">
                    <span class="brand-mark"><x-icon name="send" /></span>
                    <span class="brand-copy"><strong>{{ config('ads-platform.brand') }}</strong><small>{{ $locale === 'fa' ? 'سرویس مدیریت تبلیغات Telegram' : 'Managed Telegram advertising' }}</small></span>
                </a>
                <a class="btn btn-sm btn-secondary" href="{{ request()->fullUrlWithQuery(['lang' => $locale === 'fa' ? 'en' : 'fa']) }}">{{ $locale === 'fa' ? 'English' : 'فارسی' }}</a>
            </div>
        </header>
        <main id="legal-content" class="mini-content" tabindex="-1">
            <article class="card" style="max-width:820px;margin:24px auto">
                <div class="eyebrow">{{ $locale === 'fa' ? 'سند رسمی سرویس' : 'Service document' }}</div>
                <h1 class="page-title">{{ $title }}</h1>
                <p class="muted number">{{ $locale === 'fa' ? 'نسخه' : 'Version' }} {{ data_get($policy, 'version', '—') }}</p>
                <div class="legal-copy" style="margin-top:24px;white-space:pre-line;line-height:2">{{ $content }}</div>
            </article>
            <nav class="cluster" style="max-width:820px;margin:0 auto 40px;justify-content:center" aria-label="{{ $locale === 'fa' ? 'اسناد حقوقی' : 'Legal documents' }}">
                <a class="btn btn-secondary" href="{{ route('legal.terms', ['lang' => $locale]) }}">{{ $locale === 'fa' ? 'شرایط استفاده' : 'Terms' }}</a>
                <a class="btn btn-secondary" href="{{ route('legal.privacy', ['lang' => $locale]) }}">{{ $locale === 'fa' ? 'حریم خصوصی' : 'Privacy' }}</a>
                <a class="btn btn-secondary" href="{{ route('legal.ads-policy', ['lang' => $locale]) }}">{{ $locale === 'fa' ? 'قوانین تبلیغات' : 'Ads policy' }}</a>
            </nav>
        </main>
    </div>
</body>
</html>
