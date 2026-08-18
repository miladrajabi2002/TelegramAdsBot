@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'کیف پول' : 'Wallet') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    $kyc = data_get($currentUser, 'kyc_level', 'base');
    $kyc = $kyc instanceof \BackedEnum ? $kyc->value : (string) $kyc;
    $canRial = (bool) ($canUseRialPayments ?? ($kyc === 'rial_verified'));
    $walletToman = (int) ($walletBalanceToman ?? data_get($wallet ?? null, 'balance_toman', 0));
    $walletUsd = (float) ($walletBalanceUsd ?? data_get($wallet ?? null, 'balance_usd', 0));
    $heldToman = (int) ($heldBalanceToman ?? data_get($wallet ?? null, 'held_toman', 0));
    $zarinPayAvailable = (bool) ($zarinPayEnabled ?? config('services.zarinpay.enabled', false));
    $nowPaymentsAvailable = (bool) ($nowPaymentsEnabled ?? config('services.nowpayments.enabled', false));
    $source = $transactions ?? [];
    $items = collect(is_object($source) && method_exists($source, 'items') ? $source->items() : $source)->take(8);
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \Illuminate\Support\Carbon::parse($value)->format('Y/m/d H:i'); } catch (\Throwable) { return (string) $value; } };
    $slaFast = (int) config('ads-platform.kyc_sla_fast_minutes', 60);
    $slaMax = (int) config('ads-platform.kyc_sla_max_hours', 24);
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'امور مالی' : 'Billing' }}</div><h1 class="page-title">{{ $isFa ? 'کیف پول' : 'Wallet' }}</h1><p class="page-lead">{{ $isFa ? 'موجودی، پرداخت مستقیم و تاریخچه مالی خود را مدیریت کنید.' : 'Manage balances, direct payments, and financial history.' }}</p></div></header>

@if(!$canRial)
<section class="section" aria-label="{{ $isFa ? 'احراز هویت لازم است' : 'KYC required' }}">
    <div class="card card-warning" style="border:1px solid #fcd9a6;background:#fff8ec">
        <div class="card-head">
            <span class="quick-icon" style="background:var(--ap-warning-soft);color:var(--ap-warning)"><x-icon name="identity" /></span>
            <div>
                <h2 class="card-title" style="margin:0">{{ $isFa ? 'برای افزایش موجودی ریالی، احراز هویت لازم است' : 'Identity verification is required to top up with Rial' }}</h2>
                <p class="card-subtitle" style="margin:4px 0 0">{{ $isFa ? 'احراز سریع معمولاً '.$slaFast.' دقیقه و نهایتاً '.$slaMax.' ساعت طول می‌کشد.' : 'Fast verification usually takes '.$slaFast.' minutes, at most '.$slaMax.' hours.' }}</p>
            </div>
        </div>
        <div class="stack-sm" style="margin-top:14px">
            <a class="btn btn-primary btn-block" href="{{ $safeRoute('app.identity.show') }}"><x-icon name="identity" /><span>{{ $isFa ? 'شروع احراز هویت سریع' : 'Start fast verification' }}</span></a>
            <p class="muted" style="font-size:12px;text-align:center;margin:6px 0 0">{{ $isFa ? 'پس از تأیید، پرداخت ریالی با کارت بانکی متعلق به خودتان فعال می‌شود. پرداخت رمزارزی (NOWPayments) بدون نیاز به احراز هویت در دسترس است.' : 'Once approved, Rial payment with your own verified bank card will be enabled. Crypto payment via NOWPayments is available without verification.' }}</p>
        </div>
    </div>
</section>
@endif

<section class="wallet-hero">
    <p class="wallet-balance-label">{{ $isFa ? 'موجودی قابل‌استفاده' : 'Available balance' }}</p>
    @if($isFa)<p class="wallet-balance number">{{ number_format($walletToman) }} <span style="font-size:.48em;font-family:inherit">تومان</span></p><p class="wallet-equivalent number">≈ ${{ number_format($walletUsd, 2) }} · {{ number_format($heldToman) }} تومان رزروشده</p>@else<p class="wallet-balance number">${{ number_format($walletUsd, 2) }}</p><p class="wallet-equivalent">Available after pending holds</p>@endif
    <div class="wallet-actions"><a class="btn" href="#add-funds"><x-icon name="plus" />{{ __('ui.actions.deposit') }}</a><a class="btn btn-secondary" href="{{ $safeRoute('app.campaigns.create') }}"><x-icon name="campaign" />{{ __('ui.actions.new_campaign') }}</a></div>
</section>

<section class="section" id="add-funds">
    <div class="section-heading"><h2>{{ $isFa ? 'افزایش موجودی' : 'Add funds' }}</h2></div>
    <div class="two-column">
        <div class="card">
            <div class="card-head"><div><h3 class="card-title">ZarinPay</h3><p class="card-subtitle">{{ $isFa ? 'پرداخت ریالی با کارت بانکی تأییدشده — نیازمند احراز هویت' : 'Rial payment with a verified bank card — requires KYC' }}</p></div><x-icon name="card" class="text-primary" /></div>
            @if(!$zarinPayAvailable)
                <div class="notice"><x-icon name="clock" /><p>{{ $isFa?'درگاه ZarinPay فعلاً غیرفعال است. هیچ پرداختی از این مسیر شروع نمی‌شود.':'ZarinPay is temporarily unavailable. No payment can be initiated through this method.' }}</p></div>
            @elseif(!$canRial)
                <div class="notice notice-warning"><x-icon name="identity" /><div><strong>{{ $isFa ? 'ابتدا احراز هویت کنید' : 'Verify your identity first' }}</strong><p>{{ $isFa ? 'پرداخت ریالی فقط با کارت متعلق به صاحب حساب فعال می‌شود.' : 'Rial payments are enabled only for a card owned by the account holder.' }}</p><p class="muted" style="margin-top:4px;font-size:12px">{{ $isFa ? 'احراز سریع معمولاً '.$slaFast.' دقیقه و نهایتاً '.$slaMax.' ساعت طول می‌کشد.' : 'Fast verification usually takes '.$slaFast.' minutes, at most '.$slaMax.' hours.' }}</p></div></div>
                <a class="btn btn-primary btn-block" style="margin-top:14px" href="{{ $safeRoute('app.identity.show') }}">{{ $isFa ? 'شروع احراز هویت سریع' : 'Start fast verification' }}</a>
            @else
                <form class="form-grid" action="{{ $safeRoute('app.wallet.deposit') }}" method="post" data-loading-form data-telegram-auth>@csrf<input type="hidden" name="provider" value="zarinpay"><div class="field"><label class="field-label required" for="rial-amount">{{ $isFa ? 'مبلغ شارژ' : 'Top-up amount' }}</label><div class="input-wrap"><input class="input number" id="rial-amount" name="amount_toman" type="number" min="10000" step="1000" required value="{{ old('amount_toman') }}"><span class="input-suffix">{{ $isFa ? 'تومان' : 'Toman' }}</span></div></div><div class="field"><label class="field-label" for="funding-card">{{ $isFa ? 'کارت پرداخت' : 'Payment card' }}</label><select class="select number" id="funding-card" name="funding_card_id" required><option value="">{{ $isFa ? 'انتخاب کارت تأییدشده' : 'Choose a verified card' }}</option>@foreach(collect($fundingCards ?? data_get($currentUser, 'fundingCards', [])) as $card)<option value="{{ data_get($card, 'id') }}">•••• {{ data_get($card, 'last4', '—') }} — {{ data_get($card, 'holder_name_search', $isFa ? 'کارت تأییدشده' : 'Verified card') }}</option>@endforeach</select><p class="field-help">{{ $isFa ? 'پرداخت را فقط با همین کارت انجام دهید.' : 'Complete the payment with this card only.' }}</p></div><button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ورود به درگاه ZarinPay' : 'Continue to ZarinPay' }}</button></form>
            @endif
        </div>
        <div class="card">
            <div class="card-head"><div><h3 class="card-title">NOWPayments</h3><p class="card-subtitle">{{ $isFa ? 'پرداخت رمزارزی با فاکتور زمان‌دار — بدون نیاز به احراز هویت' : 'Crypto payment with an expiring invoice — no KYC required' }}</p></div><x-icon name="globe" class="text-primary" /></div>
            @if($nowPaymentsAvailable)<form class="form-grid" action="{{ $safeRoute('app.wallet.deposit') }}" method="post" data-loading-form data-telegram-auth>@csrf<input type="hidden" name="provider" value="nowpayments"><div class="field"><label class="field-label required" for="crypto-amount">{{ $isFa ? 'مبلغ دلاری' : 'USD amount' }}</label><div class="input-wrap"><input class="input number" id="crypto-amount" name="amount_usd" type="number" min="5" step="0.01" required value="{{ old('amount_usd') }}"><span class="input-suffix">USD</span></div><p class="field-help">{{ $isFa?'حداقل شارژ ۵ دلار است. ارز و شبکه پرداخت را در فاکتور امن NOWPayments انتخاب می‌کنید.':'Minimum top-up is $5. Choose the payment currency and network inside the secure NOWPayments hosted invoice.' }}</p></div><button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ساخت فاکتور رمزارزی' : 'Create crypto invoice' }}</button></form>@else<div class="notice"><x-icon name="clock" /><p>{{ $isFa?'NOWPayments فعلاً غیرفعال است.':'NOWPayments is temporarily unavailable.' }}</p></div>@endif
        </div>
    </div>
</section>

<section class="section" aria-labelledby="transactions-title">
    <div class="section-heading"><h2 id="transactions-title">{{ $isFa ? 'آخرین تراکنش‌ها' : 'Recent transactions' }}</h2></div>
    @if($items->isEmpty())
        <x-empty-state icon="transaction" :description="__('ui.empty.transactions')" />
    @else
        <div class="table-wrap"><table class="data-table"><thead><tr><th>{{ __('ui.common.order') }}</th><th>{{ __('ui.common.method') }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($items as $transaction)@php($status = data_get($transaction, 'status', 'pending'))<tr><td data-label="{{ __('ui.common.order') }}"><div class="table-primary"><span class="quick-icon"><x-icon name="transaction" /></span><span class="table-primary-copy"><strong>{{ data_get($transaction, 'description', $isFa ? 'تراکنش کیف پول' : 'Wallet transaction') }}</strong><small class="number">#{{ data_get($transaction, 'public_id', data_get($transaction, 'id', '—')) }}</small></span></div></td><td data-label="{{ __('ui.common.method') }}">{{ strtoupper((string) data_get($transaction, 'provider', data_get($transaction, 'type', '—'))) }}</td><td data-label="{{ __('ui.common.amount') }}" class="number">@if($isFa){{ number_format(intdiv((int) data_get($transaction, 'amount_minor', data_get($transaction, 'amount_irr', 0)), 10)) }} تومان @else ${{ number_format((float) data_get($transaction, 'display_usd', data_get($transaction, 'amount_usd', 0)), 2) }}@endif</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="$status" /></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($transaction, 'created_at')) }}</td></tr>@endforeach</tbody></table></div>
    @endif
</section>
@endsection
