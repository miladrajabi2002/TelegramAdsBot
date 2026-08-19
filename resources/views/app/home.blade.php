@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'خانه' : 'Home') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    $name = data_get($currentUser, 'first_name') ?: data_get($currentUser, 'display_name') ?: ($isFa ? 'دوست عزیز' : 'there');
    $campaignSource = $campaigns ?? $orders ?? [];
    $campaignItems = collect(is_object($campaignSource) && method_exists($campaignSource, 'items') ? $campaignSource->items() : $campaignSource)->take(3);
    $walletToman = (int) ($walletBalanceToman ?? data_get($wallet ?? null, 'balance_toman', 0));
    $walletUsd = (float) ($walletBalanceUsd ?? data_get($wallet ?? null, 'balance_usd', 0));
    $heldToman = (int) ($heldBalanceToman ?? data_get($wallet ?? null, 'held_toman', 0));
    $pendingPayment = $pendingPayment ?? null;
    $formatDate = static function ($value, bool $fa): string {
        if (!$value) return '—';
        try {
            $date = \Illuminate\Support\Carbon::parse($value);
            return $fa ? \App\Support\PersianDate::format($date, 'j F، H:mm') : $date->timezone('UTC')->format('M j, H:i');
        } catch (\Throwable) { return (string) $value; }
    };
@endphp

<header class="page-header">
    <div>
        <div class="eyebrow">{{ $isFa ? 'مرکز تبلیغات شما' : 'Your advertising hub' }}</div>
        <h1 class="page-title">{{ $isFa ? "سلام، {$name}" : "Hello, {$name}" }}</h1>
        <p class="page-lead">{{ $isFa ? 'کمپین جدید بسازید، پرداخت کنید و وضعیت اجرا را از یک مسیر شفاف دنبال کنید.' : 'Create, pay for, and follow every campaign through one clear workflow.' }}</p>
    </div>
</header>

@if($pendingPayment)
    <div class="notice notice-warning" style="margin-bottom:16px">
        <x-icon name="clock" />
        <div style="flex:1"><strong>{{ $isFa ? 'یک پرداخت ناتمام دارید' : 'You have an unfinished payment' }}</strong><p>{{ $isFa ? 'برای حفظ سفارش، پرداخت را از همان مرحله ادامه دهید.' : 'Continue from where you left off to keep the order.' }}</p></div>
        <a class="btn btn-sm btn-secondary" href="{{ $safeRoute('app.payments.show', ['payment' => data_get($pendingPayment, 'public_id', data_get($pendingPayment, 'id'))]) }}">{{ __('ui.actions.continue') }}</a>
    </div>
@endif

<section class="wallet-hero" aria-labelledby="wallet-balance-title">
    <p class="wallet-balance-label" id="wallet-balance-title">{{ $isFa ? 'موجودی قابل‌استفاده' : 'Available balance' }}</p>
    @if($isFa)
        <p class="wallet-balance number">{{ number_format($walletToman) }} <span style="font-size:.48em;font-family:inherit">تومان</span></p>
        <p class="wallet-equivalent number">≈ ${{ number_format($walletUsd, 2) }} @if($heldToman) · {{ number_format($heldToman) }} تومان رزروشده @endif</p>
    @else
        <p class="wallet-balance number">${{ number_format($walletUsd, 2) }}</p>
        <p class="wallet-equivalent">{{ $heldToman > 0 ? 'Some funds are reserved for active orders.' : 'Ready for your next campaign.' }}</p>
    @endif
    <div class="wallet-actions">
        <a class="btn" href="{{ $safeRoute('app.campaigns.create') }}"><x-icon name="plus" />{{ __('ui.actions.new_campaign') }}</a>
        <a class="btn btn-secondary" href="{{ $safeRoute('app.wallet.index') }}"><x-icon name="wallet" />{{ __('ui.actions.deposit') }}</a>
    </div>
</section>

<section class="section" aria-labelledby="quick-actions-title">
    <div class="section-heading"><h2 id="quick-actions-title">{{ $isFa ? 'دسترسی سریع' : 'Quick actions' }}</h2></div>
    <div class="quick-grid">
        <a class="quick-action" href="{{ $safeRoute('app.campaigns.create') }}"><span class="quick-icon"><x-icon name="campaign" /></span><span><strong>{{ $isFa ? 'ثبت تبلیغ' : 'Create an ad' }}</strong><small>{{ $isFa ? 'ساخت مرحله‌به‌مرحله' : 'Guided campaign setup' }}</small></span></a>
        <a class="quick-action" href="{{ $safeRoute('app.wallet.index') }}"><span class="quick-icon"><x-icon name="card" /></span><span><strong>{{ $isFa ? 'پرداخت و کیف پول' : 'Payments & wallet' }}</strong><small>{{ $isFa ? 'ریالی یا رمزارزی' : 'Rial or crypto' }}</small></span></a>
        <a class="quick-action" href="{{ $safeRoute('app.help') }}"><span class="quick-icon"><x-icon name="support" /></span><span><strong>{{ $isFa ? 'راهنما و قوانین' : 'Help & policies' }}</strong><small>{{ $isFa ? 'پاسخ‌های کوتاه و روشن' : 'Clear, concise answers' }}</small></span></a>
    </div>
</section>

<section class="section" aria-labelledby="recent-campaigns-title">
    <div class="section-heading"><h2 id="recent-campaigns-title">{{ $isFa ? 'کمپین‌های اخیر' : 'Recent campaigns' }}</h2><a class="btn btn-text btn-sm" href="{{ $safeRoute('app.campaigns.index') }}">{{ $isFa ? 'مشاهده همه' : 'View all' }}</a></div>
    @if($campaignItems->isEmpty())
        <x-empty-state icon="campaign" :description="__('ui.empty.campaigns')"><a class="btn btn-primary" href="{{ $safeRoute('app.campaigns.create') }}">{{ __('ui.actions.new_campaign') }}</a></x-empty-state>
    @else
        <div class="stack-sm">
            @foreach($campaignItems as $campaign)
                @php
                    $status = data_get($campaign, 'status', 'draft');
                    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
                    $publicId = data_get($campaign, 'public_id', data_get($campaign, 'id'));
                    $title = data_get($campaign, 'currentRevision.internal_title') ?: data_get($campaign, 'current_revision.internal_title') ?: ($isFa ? 'کمپین بدون عنوان' : 'Untitled campaign');
                @endphp
                <a class="campaign-card" href="{{ $safeRoute('app.campaigns.show', ['campaign' => $publicId]) }}">
                    <div class="campaign-card-title"><div class="cluster"><strong>{{ $title }}</strong><x-status-chip :value="$status" /></div><small class="number">#{{ $publicId ?: '—' }} · {{ $formatDate(data_get($campaign, 'created_at'), $isFa) }}</small></div>
                    <div class="campaign-card-footer"><span><small class="muted">{{ $isFa ? 'مبلغ سفارش' : 'Order total' }}</small><strong class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'total_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0), 2) }}@endif</strong></span><x-icon name="chevron" /></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

<section class="section card card-soft">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'Ads Platform چگونه کار می‌کند؟' : 'How Ads Platform works' }}</h2><p class="card-subtitle">{{ $isFa ? 'از ثبت سفارش تا گزارش، در چهار قدم' : 'From order to report in four steps' }}</p></div><x-icon name="send" class="text-primary" /></div>
    <ol class="journey">
        <li class="journey-item is-done"><span class="journey-node"><x-icon name="check" size="sm" /></span><span class="journey-copy"><strong>{{ $isFa ? 'کمپین را می‌سازید' : 'Build your campaign' }}</strong><small>{{ $isFa ? 'متن، مقصد، کانال‌ها و بودجه را مشخص کنید.' : 'Set the copy, destination, channels, and budget.' }}</small></span></li>
        <li class="journey-item is-done"><span class="journey-node"><x-icon name="check" size="sm" /></span><span class="journey-copy"><strong>{{ $isFa ? 'پرداخت می‌کنید' : 'Choose how to pay' }}</strong><small>{{ $isFa ? 'از کیف پول یا مستقیم با درگاه پرداخت کنید.' : 'Use your wallet or pay the order directly.' }}</small></span></li>
        <li class="journey-item is-current"><span class="journey-node"><x-icon name="clock" size="sm" /></span><span class="journey-copy"><strong>{{ $isFa ? 'بررسی و ثبت انجام می‌شود' : 'Review and submission' }}</strong><small>{{ $isFa ? 'پشتیبانی بررسی اولیه را انجام می‌دهد و تبلیغ برای Telegram ثبت می‌شود.' : 'Support reviews the ad, then submits it to Telegram.' }}</small></span></li>
        <li class="journey-item"><span class="journey-node"><x-icon name="chart" size="sm" /></span><span class="journey-copy"><strong>{{ $isFa ? 'گزارش را دنبال می‌کنید' : 'Follow the report' }}</strong><small>{{ $isFa ? 'پس از شروع اجرا، آخرین آمار در صفحه کمپین نمایش داده می‌شود.' : 'Once live, the latest metrics appear on the campaign page.' }}</small></span></li>
    </ol>
</section>
@endsection
