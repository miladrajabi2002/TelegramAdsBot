@php
    $locale = $locale ?? app()->getLocale();
    $isFa = $locale === 'fa';
    $direction = $isFa ? 'rtl' : 'ltr';
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentAdmin = $admin ?? auth('admin')->user();
    $adminName = data_get($currentAdmin, 'name') ?: ($isFa ? 'مدیر سیستم' : 'System admin');
    $adminRole = data_get($currentAdmin, 'role') ?: ($isFa ? 'اپراتور' : 'Operator');
    $pendingKycCount = (int) ($pendingKycCount ?? 0);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/ads-platform-192.svg" type="image/svg+xml">
    <title>@yield('title', __('ui.admin_nav.dashboard')) — {{ __('ui.brand') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#admin-content">{{ __('ui.skip') }}</a>
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="{{ $isFa ? 'ناوبری پنل مدیریت' : 'Admin navigation' }}">
            <a class="brand-lockup" href="{{ $safeRoute('admin.dashboard') }}">
                <span class="brand-mark"><x-icon name="send" /></span>
                <span class="brand-copy"><strong>{{ __('ui.brand') }}</strong><small>{{ $isFa ? 'مرکز عملیات' : 'Operations center' }}</small></span>
            </a>

            <nav class="sidebar-nav">
                <a class="sidebar-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.dashboard') }}"><x-icon name="home" /><span>{{ __('ui.admin_nav.dashboard') }}</span></a>
                <div class="sidebar-label">{{ __('ui.admin_nav.operations') }}</div>
                <a class="sidebar-link {{ request()->routeIs('admin.orders.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.orders.index') }}"><x-icon name="campaign" /><span>{{ __('ui.admin_nav.orders') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.kyc.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.kyc.index') }}"><x-icon name="identity" /><span>{{ __('ui.admin_nav.kyc') }}</span>@if($pendingKycCount)<b class="sidebar-badge number">{{ $pendingKycCount }}</b>@endif</a>
                <a class="sidebar-link {{ request()->routeIs('admin.transactions.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.transactions.index') }}"><x-icon name="transaction" /><span>{{ __('ui.admin_nav.transactions') }}</span></a>
                <div class="sidebar-label">{{ __('ui.admin_nav.customers') }}</div>
                <a class="sidebar-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.users.index') }}"><x-icon name="users" /><span>{{ __('ui.admin_nav.users') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.support.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.support.index') }}"><x-icon name="support" /><span>{{ __('ui.admin_nav.support') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.broadcasts.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.broadcasts.index') }}"><x-icon name="send" /><span>{{ __('ui.admin_nav.broadcasts') }}</span></a>
                <div class="sidebar-label">{{ __('ui.admin_nav.content') }}</div>
                <a class="sidebar-link {{ request()->routeIs('admin.channels.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.channels.index') }}"><x-icon name="channel" /><span>{{ __('ui.admin_nav.channels') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.reports.index') }}"><x-icon name="chart" /><span>{{ __('ui.admin_nav.reports') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.audit.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.audit.index') }}"><x-icon name="document" /><span>{{ __('ui.admin_nav.audit') }}</span></a>
                <a class="sidebar-link {{ request()->routeIs('admin.settings.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.settings.index') }}"><x-icon name="settings" /><span>{{ __('ui.admin_nav.settings') }}</span></a>
                {{-- P1-17: previously logout was only reachable via the mobile drawer's "More"
                     menu, which is hidden on desktop. Adding it to the sidebar (desktop)
                     and also keeping it in the drawer means admins can sign out from any
                     device without an extra click. --}}
                @if(\Illuminate\Support\Facades\Route::has('admin.logout'))
                    <form action="{{ route('admin.logout') }}" method="post" data-confirm="{{ $isFa ? 'از حساب مدیریت خارج می‌شوید؟' : 'Sign out of admin?' }}">@csrf<button class="sidebar-link text-danger" style="width:100%;border:0;background:transparent" type="submit"><x-icon name="logout" /><span>{{ __('ui.actions.logout') }}</span></button></form>
                @endif
            </nav>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <div>
                    <strong>@yield('page-title', __('ui.admin_nav.dashboard'))</strong>
                    <div class="subtle" style="font-size:11px">@yield('page-kicker', $isFa ? 'مرکز عملیات و گزارش' : 'Operations and reporting')</div>
                </div>
                <div class="cluster" style="gap:6px">
                    <button class="icon-btn" type="button" aria-label="{{ $isFa ? 'اعلان‌ها' : 'Notifications' }}"><x-icon name="bell" /></button>
                    <div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($adminName, 0, 1)) }}</div>
                    <div class="brand-copy"><strong>{{ $adminName }}</strong><small>{{ $adminRole }}</small></div>
                </div>
            </header>

            <main id="admin-content" class="admin-content" tabindex="-1">
                <x-flash />
                @yield('content')
            </main>
        </div>

        <nav class="admin-mobile-nav" aria-label="{{ $isFa ? 'ناوبری مدیریت' : 'Admin navigation' }}">
            <a class="mini-nav-item {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.dashboard') }}"><x-icon name="home" /><span>{{ __('ui.admin_nav.dashboard') }}</span></a>
            <a class="mini-nav-item {{ request()->routeIs('admin.orders.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.orders.index') }}"><x-icon name="campaign" /><span>{{ __('ui.admin_nav.orders') }}</span></a>
            <a class="mini-nav-item {{ request()->routeIs('admin.kyc.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.kyc.index') }}"><x-icon name="identity" /><span>{{ __('ui.admin_nav.kyc') }}</span></a>
            <a class="mini-nav-item {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}" href="{{ $safeRoute('admin.users.index') }}"><x-icon name="users" /><span>{{ __('ui.admin_nav.users') }}</span></a>
            <button class="mini-nav-item" type="button" data-drawer-toggle="#admin-more-drawer" aria-controls="admin-more-drawer" aria-expanded="false"><x-icon name="more" /><span>{{ __('ui.admin_nav.more') }}</span></button>
        </nav>

        <div class="drawer-scrim" data-drawer-scrim></div>
        <aside class="drawer" id="admin-more-drawer" aria-hidden="true" aria-label="{{ __('ui.admin_nav.more') }}">
            <div class="drawer-head"><div><strong>{{ __('ui.admin_nav.more') }}</strong><div class="subtle" style="font-size:12px">{{ $adminName }}</div></div><button class="icon-btn" type="button" data-drawer-close aria-label="{{ __('ui.actions.close') }}"><x-icon name="plus" style="transform:rotate(45deg)" /></button></div>
            <nav class="sidebar-nav">
                <a class="sidebar-link" href="{{ $safeRoute('admin.transactions.index') }}"><x-icon name="transaction" /><span>{{ __('ui.admin_nav.transactions') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.channels.index') }}"><x-icon name="channel" /><span>{{ __('ui.admin_nav.channels') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.reports.index') }}"><x-icon name="chart" /><span>{{ __('ui.admin_nav.reports') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.audit.index') }}"><x-icon name="document" /><span>{{ __('ui.admin_nav.audit') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.broadcasts.index') }}"><x-icon name="send" /><span>{{ __('ui.admin_nav.broadcasts') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.support.index') }}"><x-icon name="support" /><span>{{ __('ui.admin_nav.support') }}</span></a>
                <a class="sidebar-link" href="{{ $safeRoute('admin.settings.index') }}"><x-icon name="settings" /><span>{{ __('ui.admin_nav.settings') }}</span></a>
                @if(\Illuminate\Support\Facades\Route::has('admin.logout'))
                    <form action="{{ route('admin.logout') }}" method="post">@csrf<button class="sidebar-link text-danger" style="width:100%;border:0;background:transparent" type="submit"><x-icon name="logout" /><span>{{ __('ui.actions.logout') }}</span></button></form>
                @endif
            </nav>
        </aside>
    </div>
    @stack('scripts')
</body>
</html>
