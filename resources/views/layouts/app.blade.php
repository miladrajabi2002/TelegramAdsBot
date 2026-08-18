@php
    $locale = $locale ?? app()->getLocale();
    $isFa = $locale === 'fa';
    $direction = $isFa ? 'rtl' : 'ltr';
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    $displayName = data_get($currentUser, 'display_name') ?: data_get($currentUser, 'first_name') ?: ($isFa ? 'کاربر تلگرام' : 'Telegram user');
    $avatar = data_get($currentUser, 'photo_url');
    $initial = mb_strtoupper(mb_substr($displayName, 0, 1));
    $nextLocale = $isFa ? 'en' : 'fa';
    $localeUrl = \Illuminate\Support\Facades\Route::has('app.locale') ? route('app.locale', ['locale' => $nextLocale]) : request()->fullUrlWithQuery(['lang' => $nextLocale]);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/ads-platform-192.svg" type="image/svg+xml">
    <title>@yield('title', __('ui.brand'))</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main-content">{{ __('ui.skip') }}</a>
    <div class="mini-shell">
        <header class="mini-topbar">
            <div class="mini-topbar-inner">
                <a class="brand-lockup" href="{{ $safeRoute('app.home') }}" aria-label="{{ __('ui.brand') }}">
                    <span class="brand-mark"><x-icon name="send" /></span>
                    <span class="brand-copy"><strong>{{ __('ui.brand') }}</strong><small>{{ __('ui.tagline') }}</small></span>
                </a>
                <div class="cluster" style="gap:4px">
                    <a class="icon-btn" href="{{ $localeUrl }}" aria-label="{{ __('ui.language') }}"><x-icon name="globe" /></a>
                    <a class="avatar" href="{{ $safeRoute('app.account') }}" aria-label="{{ $displayName }}">
                        @if($avatar)<img src="{{ $avatar }}" alt="">@else{{ $initial }}@endif
                    </a>
                </div>
            </div>
        </header>

        <main id="main-content" class="mini-content" tabindex="-1">
            <x-flash />
            @yield('content')
        </main>

        <nav class="mini-bottom-nav" aria-label="{{ $isFa ? 'ناوبری اصلی' : 'Main navigation' }}">
            <div class="mini-bottom-nav-inner">
                <a class="mini-nav-item {{ request()->routeIs('app.home') ? 'is-active' : '' }}" href="{{ $safeRoute('app.home') }}" @if(request()->routeIs('app.home')) aria-current="page" @endif><x-icon name="home" /><span>{{ __('ui.nav.home') }}</span></a>
                <a class="mini-nav-item {{ request()->routeIs('app.campaigns.index', 'app.campaigns.show') ? 'is-active' : '' }}" href="{{ $safeRoute('app.campaigns.index') }}" @if(request()->routeIs('app.campaigns.index', 'app.campaigns.show')) aria-current="page" @endif><x-icon name="campaign" /><span>{{ __('ui.nav.campaigns') }}</span></a>
                <a class="mini-nav-item mini-nav-create {{ request()->routeIs('app.campaigns.create') ? 'is-active' : '' }}" href="{{ $safeRoute('app.campaigns.create') }}" @if(request()->routeIs('app.campaigns.create')) aria-current="page" @endif><x-icon name="plus" /><span>{{ __('ui.nav.create') }}</span></a>
                <a class="mini-nav-item {{ request()->routeIs('app.wallet.*') ? 'is-active' : '' }}" href="{{ $safeRoute('app.wallet.index') }}" @if(request()->routeIs('app.wallet.*')) aria-current="page" @endif><x-icon name="wallet" /><span>{{ __('ui.nav.wallet') }}</span></a>
                <a class="mini-nav-item {{ request()->routeIs('app.account', 'app.identity.*', 'app.help') ? 'is-active' : '' }}" href="{{ $safeRoute('app.account') }}" @if(request()->routeIs('app.account', 'app.identity.*', 'app.help')) aria-current="page" @endif><x-icon name="user" /><span>{{ __('ui.nav.account') }}</span></a>
            </div>
        </nav>
    </div>
    @stack('scripts')
</body>
</html>
