@php
    $locale = $locale ?? app()->getLocale();
    $isFa = $locale === 'fa';
    $direction = $isFa ? 'rtl' : 'ltr';
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    $displayName = data_get($currentUser, 'display_name') ?: data_get($currentUser, 'first_name') ?: ($isFa ? 'کاربر تلگرام' : 'Telegram user');
    $avatar = data_get($currentUser, 'id') ? \App\Providers\AppServiceProvider::avatarUrl($currentUser) : null;
    $initial = mb_strtoupper(mb_substr($displayName, 0, 1));
    $nextLocale = $isFa ? 'en' : 'fa';
    $localeUrl = \Illuminate\Support\Facades\Route::has('app.locale') ? route('app.locale', ['locale' => $nextLocale]) : request()->fullUrlWithQuery(['lang' => $nextLocale]);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    {{-- viewport meta — disables iOS Safari auto-zoom on input focus.
        iOS Safari automatically zooms the viewport when an input is
        focused AND its computed font-size is < 16px. There are two
        complementary fixes:
          1. Bump all .input/.select/.textarea/.channel-search-input
             font-sizes to 16px (done in app.css).
          2. Pin the viewport with maximum-scale=1 + user-scalable=no
             so even if a stray sub-16px input slips through, the
             browser refuses to zoom.
        (2) alone is enough for a Telegram Mini App — the Mini App
        shell already provides its own gestures, and disabled user
        zoom matches the native-app feel users expect inside a bot. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
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
    {{-- The full-screen splash loader was removed in favour of a slim
         top progress bar that sits under the header (see .nav-progress).
         The bar animates whenever the user navigates between pages or
         submits a form, giving a clear but unobtrusive loading cue. --}}
    <div class="mini-shell">
        <header class="mini-topbar">
            <div class="mini-topbar-inner">
                <a class="brand-lockup" href="{{ $safeRoute('app.home') }}" aria-label="{{ __('ui.brand') }}">
                    <span class="brand-mark"><x-icon name="send" /></span>
                    <span class="brand-copy"><strong>{{ __('ui.brand') }}</strong><small>{{ __('ui.tagline') }}</small></span>
                </a>
                <div class="cluster" style="gap:8px">
                    <a class="lang-pill" href="{{ $localeUrl }}" aria-label="{{ $isFa ? 'Switch to English' : 'تغییر به فارسی' }}" title="{{ $isFa ? 'Switch to English' : 'تغییر به فارسی' }}">
                        <span class="lang-pill-current">{{ $isFa ? 'FA' : 'EN' }}</span>
                        <span class="lang-pill-sep" aria-hidden="true">·</span>
                        <span class="lang-pill-next">{{ $isFa ? 'EN' : 'FA' }}</span>
                    </a>
                    <a class="avatar" href="{{ $safeRoute('app.account') }}" aria-label="{{ $displayName }}">
                        @if($avatar)
                            <img src="{{ $avatar }}" alt="" decoding="async" loading="lazy" onerror="this.style.display='none'; this.parentElement.classList.add('avatar-fallback')">
                            <span class="avatar-initial" aria-hidden="true">{{ $initial }}</span>
                        @else
                            {{ $initial }}
                        @endif
                    </a>
                </div>
            </div>
            {{-- Top loading progress bar. Animates on navigation and form
                 submit. Visibility is toggled from app.js. --}}
            <div class="nav-progress" data-nav-progress aria-hidden="true"><span class="nav-progress-bar"></span></div>
        </header>

        <main id="main-content" class="mini-content {{ $contentModifiers ?? '' }}" tabindex="-1">
            <x-flash />
            @yield('content')
        </main>

        <nav class="mini-bottom-nav" aria-label="{{ $isFa ? 'ناوبری اصلی' : 'Main navigation' }}" data-mini-bottom-nav>
            <div class="mini-bottom-nav-glow" aria-hidden="true"></div>
            <div class="mini-bottom-nav-inner">
                <span class="mini-nav-indicator" aria-hidden="true"></span>
                <a class="mini-nav-item {{ request()->routeIs('app.home') ? 'is-active' : '' }}" href="{{ $safeRoute('app.home') }}" @if(request()->routeIs('app.home')) aria-current="page" @endif data-nav-tooltip="{{ __('ui.nav.home') }}">
                    <span class="mini-nav-icon"><x-icon name="home" /></span>
                    <span class="mini-nav-label">{{ __('ui.nav.home') }}</span>
                </a>
                <a class="mini-nav-item {{ request()->routeIs('app.campaigns.index', 'app.campaigns.show') ? 'is-active' : '' }}" href="{{ $safeRoute('app.campaigns.index') }}" @if(request()->routeIs('app.campaigns.index', 'app.campaigns.show')) aria-current="page" @endif data-nav-tooltip="{{ __('ui.nav.campaigns') }}">
                    <span class="mini-nav-icon"><x-icon name="campaign" /></span>
                    <span class="mini-nav-label">{{ __('ui.nav.campaigns') }}</span>
                </a>
                <a class="mini-nav-item mini-nav-create {{ request()->routeIs('app.campaigns.create') ? 'is-active' : '' }}" href="{{ $safeRoute('app.campaigns.create') }}" @if(request()->routeIs('app.campaigns.create', 'app.campaigns.edit')) aria-current="page" @endif data-nav-tooltip="{{ __('ui.nav.create') }}">
                    <span class="mini-nav-create-orb"><x-icon name="plus" /></span>
                    <span class="mini-nav-label">{{ __('ui.nav.create') }}</span>
                </a>
                <a class="mini-nav-item {{ request()->routeIs('app.wallet.*') ? 'is-active' : '' }}" href="{{ $safeRoute('app.wallet.index') }}" @if(request()->routeIs('app.wallet.*')) aria-current="page" @endif data-nav-tooltip="{{ __('ui.nav.wallet') }}">
                    <span class="mini-nav-icon"><x-icon name="wallet" /></span>
                    <span class="mini-nav-label">{{ __('ui.nav.wallet') }}</span>
                </a>
                <a class="mini-nav-item {{ request()->routeIs('app.account', 'app.identity.*', 'app.help') ? 'is-active' : '' }}" href="{{ $safeRoute('app.account') }}" @if(request()->routeIs('app.account', 'app.identity.*', 'app.help')) aria-current="page" @endif data-nav-tooltip="{{ __('ui.nav.account') }}">
                    <span class="mini-nav-icon"><x-icon name="user" /></span>
                    <span class="mini-nav-label">{{ __('ui.nav.account') }}</span>
                </a>
            </div>
        </nav>
    </div>
    @stack('scripts')

    @if(request()->routeIs('app.campaigns.create', 'app.campaigns.edit'))
    <script>
    (() => {
        const wizard = document.querySelector('[data-campaign-order-wizard]');
        if (!wizard) return;

        // ── Keyboard / fixed action-bar correction ──────────────────────
        // Telegram clients use two keyboard behaviours: some shrink the
        // layout viewport, others only shrink/pan VisualViewport. The page's
        // older handler only watched offsetTop/innerHeight, so in the common
        // VisualViewport-height case the Continue/Back bar jumped above the
        // keyboard. This handler is registered AFTER the page handler and
        // writes the final correction value.
        const root = document.documentElement;
        const telegram = window.Telegram?.WebApp;
        const vv = window.visualViewport;
        const telegramStableHeight = () => Number(telegram?.viewportStableHeight || 0);
        let stableHeight = Math.max(window.innerHeight, telegramStableHeight(), vv?.height || 0);
        let rafId = 0;

        const isEditable = (element) => {
            if (!(element instanceof HTMLElement) || !wizard.contains(element)) return false;
            if (element.matches('textarea, select, [contenteditable="true"]')) return true;
            if (!element.matches('input')) return false;
            return !['button', 'checkbox', 'file', 'hidden', 'radio', 'reset', 'submit']
                .includes((element.type || 'text').toLowerCase());
        };

        const measureKeyboard = () => {
            rafId = 0;
            const editing = isEditable(document.activeElement);

            if (!editing) {
                stableHeight = Math.max(stableHeight, window.innerHeight, telegramStableHeight(), vv?.height || 0);
                root.style.setProperty('--campaign-create-keyboard-shift', '0px');
                root.classList.remove('campaign-create-keyboard-open');
                return;
            }

            const visualBottom = vv
                ? Number(vv.height || 0) + Math.max(0, Number(vv.offsetTop || 0))
                : window.innerHeight;
            const visualOcclusion = vv ? Math.max(0, stableHeight - visualBottom) : 0;
            const layoutShrink = Math.max(0, stableHeight - window.innerHeight);
            const rawShift = Math.max(visualOcclusion, layoutShrink);
            const shift = rawShift >= 20 ? Math.round(rawShift) : 0;

            root.style.setProperty('--campaign-create-keyboard-shift', `${shift}px`);
            root.classList.toggle('campaign-create-keyboard-open', shift > 0);
        };

        const scheduleMeasure = () => {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(measureKeyboard);
        };

        vv?.addEventListener('resize', scheduleMeasure, { passive: true });
        vv?.addEventListener('scroll', scheduleMeasure, { passive: true });
        window.addEventListener('resize', scheduleMeasure, { passive: true });
        document.addEventListener('focusin', scheduleMeasure, true);
        document.addEventListener('focusout', () => setTimeout(scheduleMeasure, 80), true);
        telegram?.onEvent?.('viewportChanged', scheduleMeasure);
        scheduleMeasure();

        // ── Live Step-5 price summary ───────────────────────────────────
        // Step 4 already keeps media_budget_toman in a hidden input. The old
        // Step 5 was rendered once from the controller's default quote and
        // never listened to that live value, so its breakdown looked stale
        // even though the backend charged the correct amount.
        const budgetHidden = wizard.querySelector('[data-budget-toman-hidden]');
        const budgetGram = wizard.querySelector('[data-budget-gram-input]');
        const panes = Array.from(wizard.querySelectorAll('[data-wizard-step]'));
        const reviewPane = panes[panes.length - 1];
        const rows = reviewPane ? Array.from(reviewPane.querySelectorAll('.definition-list > .definition-row')) : [];
        if (!budgetHidden || rows.length < 4) return;

        const digitMap = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9','٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};
        const numberFromText = (text) => {
            const latin = String(text || '').replace(/[۰-۹٠-٩]/g, (d) => digitMap[d] || d);
            const cleaned = latin.replace(/[^0-9.\-]/g, '');
            return Number.parseFloat(cleaned || '0') || 0;
        };

        const initialMedia = numberFromText(rows[0].querySelector('dd')?.textContent);
        const initialService = numberFromText(rows[1].querySelector('dd')?.textContent);
        const initialGateway = numberFromText(rows[2].querySelector('dd')?.textContent);
        const serviceRate = initialMedia > 0 ? initialService / initialMedia : 0;
        const gatewayBase = initialMedia + initialService;
        const gatewayRate = gatewayBase > 0 ? initialGateway / gatewayBase : 0;
        const isFa = document.documentElement.lang === 'fa';
        const formatter = new Intl.NumberFormat(isFa ? 'fa-IR' : 'en-US', { maximumFractionDigits: 1 });

        const setMoney = (row, amount) => {
            const dd = row?.querySelector('dd');
            if (!dd) return;
            const strong = dd.querySelector('strong');
            const text = `${formatter.format(Math.max(0, amount))} ${isFa ? 'تومان' : 'Toman'}`;
            if (strong) strong.textContent = text;
            else dd.textContent = text;
        };

        const syncPriceSummary = () => {
            const media = Math.max(0, Number.parseInt(budgetHidden.value || '0', 10) || 0);
            const service = Math.ceil(media * serviceRate);
            const gateway = Math.ceil((media + service) * gatewayRate);
            const total = media + service + gateway;

            setMoney(rows[0], media);
            setMoney(rows[1], service);
            setMoney(rows[2], gateway);
            setMoney(rows[3], total);
        };

        budgetGram?.addEventListener('input', () => requestAnimationFrame(syncPriceSummary));
        budgetGram?.addEventListener('change', syncPriceSummary);
        wizard.addEventListener('click', () => queueMicrotask(syncPriceSummary));
        new MutationObserver(syncPriceSummary).observe(budgetHidden, { attributes: true, attributeFilter: ['value'] });
        syncPriceSummary();
    })();
    </script>
    @endif
</body>
</html>
