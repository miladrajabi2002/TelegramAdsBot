@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'پرداخت آزمایشی' : 'Mock payment') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static function (string $name, array $parameters = []): string {
        if (! \Illuminate\Support\Facades\Route::has($name)) return '#';
        try { return route($name, $parameters); } catch (\Throwable) { return '#'; }
    };
    $intent = $paymentIntent ?? $intent ?? null;
    $id = data_get($intent, 'public_id', data_get($intent, 'id'));
    $currency = strtoupper((string) data_get($intent, 'currency', 'IRR'));
    $minor = (int) data_get($intent, 'amount_minor', 0);
    $displayAmount = $currency === 'IRR' ? number_format(intdiv($minor, 10)).' '.($isFa ? 'تومان' : 'Toman') : number_format($minor / 100, 2).' '.$currency;
@endphp
<header class="page-header"><div><div class="eyebrow">LOCAL / TEST ONLY</div><h1 class="page-title">{{ $isFa ? 'پرداخت آزمایشی' : 'Mock payment' }}</h1><p class="page-lead">{{ $isFa ? 'این صفحه فقط در محیط local برای آزمایش چرخه پرداخت نمایش داده می‌شود.' : 'This page is available only in local mode to test the payment lifecycle.' }}</p></div></header>

<div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'این درگاه واقعی نیست و هیچ مبلغی جابه‌جا نمی‌شود.' : 'This is not a real gateway and no money will move.' }}</p></div>
<section class="card section" style="max-width:560px;margin-inline:auto">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'تأیید نتیجه پرداخت' : 'Choose the payment result' }}</h2><p class="card-subtitle number">#{{ $id ?: '—' }}</p></div><span class="quick-icon"><x-icon name="card" /></span></div>
    <dl class="definition-list"><div class="definition-row"><dt>{{ __('ui.common.amount') }}</dt><dd class="number">{{ $displayAmount }}</dd></div><div class="definition-row"><dt>{{ __('ui.common.method') }}</dt><dd>{{ strtoupper((string) data_get($intent, 'provider', 'mock')) }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'هدف پرداخت' : 'Purpose' }}</dt><dd>{{ data_get($intent, 'purpose', '—') instanceof \BackedEnum ? data_get($intent, 'purpose')->value : data_get($intent, 'purpose', '—') }}</dd></div><div class="definition-row"><dt>{{ __('ui.common.status') }}</dt><dd><x-status-chip :value="data_get($intent, 'status', 'created')" /></dd></div></dl>
    <div class="two-column" style="margin-top:18px">
        <form action="{{ $safeRoute('payments.zarinpay.mock.confirm', ['intent' => $id]) }}" method="post" data-loading-form>@csrf<button class="btn btn-primary btn-block" type="submit"><x-icon name="check" />{{ $isFa ? 'شبیه‌سازی پرداخت موفق' : 'Simulate success' }}</button></form>
        <form action="{{ $safeRoute('payments.zarinpay.mock.cancel', ['intent' => $id]) }}" method="post" data-loading-form>@csrf<button class="btn btn-danger btn-block" type="submit"><x-icon name="warning" />{{ $isFa ? 'شبیه‌سازی پرداخت ناموفق' : 'Simulate failure' }}</button></form>
    </div>
</section>
@endsection
