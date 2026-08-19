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
    $formatDate = static function ($value) use ($isFa): string { if (!$value) return '—'; try { $date = \Illuminate\Support\Carbon::parse($value); return $isFa ? \App\Support\PersianDate::format($date) : $date->timezone('UTC')->format('Y/m/d H:i'); } catch (\Throwable) { return (string) $value; } };
    $slaFast = (int) config('ads-platform.kyc_sla_fast_minutes', 60);
    $slaMax = (int) config('ads-platform.kyc_sla_max_hours', 24);
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'امور مالی' : 'Billing' }}</div><h1 class="page-title">{{ $isFa ? 'کیف پول' : 'Wallet' }}</h1><p class="page-lead">{{ $isFa ? 'موجودی، پرداخت مستقیم و تاریخچه مالی خود را مدیریت کنید.' : 'Manage balances, direct payments, and financial history.' }}</p></div></header>

<section class="wallet-hero">
    <p class="wallet-balance-label">{{ $isFa ? 'موجودی قابل‌استفاده' : 'Available balance' }}</p>
    @if($isFa)<p class="wallet-balance number">{{ number_format($walletToman) }} <span style="font-size:.48em;font-family:inherit">تومان</span></p><p class="wallet-equivalent number">≈ ${{ number_format($walletUsd, 2) }} · {{ number_format($heldToman) }} تومان رزروشده</p>@else<p class="wallet-balance number">${{ number_format($walletUsd, 2) }}</p><p class="wallet-equivalent">Available after pending holds</p>@endif
    <div class="wallet-actions"><a class="btn" href="#add-funds"><x-icon name="plus" />{{ __('ui.actions.deposit') }}</a><a class="btn btn-secondary" href="{{ $safeRoute('app.campaigns.create') }}"><x-icon name="campaign" />{{ __('ui.actions.new_campaign') }}</a></div>
</section>

<section class="section" id="add-funds">
    <div class="section-heading"><h2>{{ $isFa ? 'افزایش موجودی' : 'Add funds' }}</h2></div>
    <div class="two-column">
        {{-- ZarinPay card --}}
        <div class="card pay-method-card">
            <div class="pay-method-head">
                <span class="pay-method-icon pay-method-icon-rial"><x-icon name="card" /></span>
                <div>
                    <h3 class="pay-method-title">ZarinPay</h3>
                    <p class="pay-method-sub">{{ $isFa ? 'ریالی — کارت بانکی' : 'Rial — bank card' }}</p>
                </div>
                @if($canRial)<span class="pay-method-badge pay-method-badge-ok">{{ $isFa ? 'فعال' : 'Active' }}</span>@elseif($zarinPayAvailable)<span class="pay-method-badge pay-method-badge-warn">{{ $isFa ? 'نیازمند احراز' : 'KYC needed' }}</span>@else<span class="pay-method-badge pay-method-badge-off">{{ $isFa ? 'غیرفعال' : 'Off' }}</span>@endif
            </div>
            <p class="pay-method-desc">{{ $isFa ? 'پرداخت با کارت بانکی تأییدشده به‌صورت ریالی. تسویه فوری پس از تأیید درگاه.' : 'Pay rial with a verified bank card. Settled instantly after gateway approval.' }}</p>
            @if(!$zarinPayAvailable)
                <div class="notice"><x-icon name="clock" /><p>{{ $isFa?'درگاه ZarinPay فعلاً غیرفعال است.':'ZarinPay is temporarily unavailable.' }}</p></div>
            @elseif(!$canRial)
                <div class="notice notice-warning"><x-icon name="identity" /><div><strong>{{ $isFa ? 'ابتدا احراز هویت کنید' : 'Verify your identity first' }}</strong><p>{{ $isFa ? 'پرداخت ریالی فقط با کارت متعلق به صاحب حساب فعال می‌شود.' : 'Rial payments are enabled only for a card owned by the account holder.' }}</p></div></div>
                <a class="btn btn-primary btn-block" style="margin-top:14px" href="{{ $safeRoute('app.identity.show') }}">{{ $isFa ? 'شروع احراز هویت سریع' : 'Start fast verification' }}</a>
            @else
                <form class="form-grid" action="{{ $safeRoute('app.wallet.deposit') }}" method="post" data-loading-form data-telegram-auth>@csrf<input type="hidden" name="provider" value="zarinpay"><div class="field"><label class="field-label required" for="rial-amount">{{ $isFa ? 'مبلغ شارژ' : 'Top-up amount' }}</label><div class="input-wrap"><input class="input number ltr" id="rial-amount" name="amount_toman" type="text" inputmode="numeric" pattern="[0-9]+" data-persian-digits data-amount-field data-amount-integer required value="{{ old('amount_toman') }}" placeholder="100000" min="10000"><span class="input-suffix">{{ $isFa ? 'تومان' : 'Toman' }}</span></div><p class="field-help">{{ $isFa ? 'حداقل ۱۰٬۰۰۰ تومان. اعداد فارسی هم پذیرفته می‌شود.' : 'Minimum 10,000 Toman. Persian digits are accepted.' }}</p></div><div class="field"><label class="field-label" for="funding-card">{{ $isFa ? 'کارت پرداخت' : 'Payment card' }}</label><select class="select number ltr" id="funding-card" name="funding_card_id" required><option value="">{{ $isFa ? 'انتخاب کارت تأییدشده' : 'Choose a verified card' }}</option>@foreach(collect($fundingCards ?? data_get($currentUser, 'fundingCards', [])) as $card)<option value="{{ data_get($card, 'id') }}">•••• {{ data_get($card, 'last4', '—') }} — {{ data_get($card, 'holder_name_search', $isFa ? 'کارت تأییدشده' : 'Verified card') }}</option>@endforeach</select><p class="field-help">{{ $isFa ? 'پرداخت را فقط با همین کارت انجام دهید.' : 'Complete the payment with this card only.' }}</p></div><button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ورود به درگاه ZarinPay' : 'Continue to ZarinPay' }}</button></form>
            @endif
        </div>

        {{-- NOWPayments card --}}
        <div class="card pay-method-card">
            <div class="pay-method-head">
                <span class="pay-method-icon pay-method-icon-crypto"><x-icon name="globe" /></span>
                <div>
                    <h3 class="pay-method-title">NOWPayments</h3>
                    <p class="pay-method-sub">{{ $isFa ? 'رمزارزی — بدون احراز' : 'Crypto — no KYC' }}</p>
                </div>
                @if($nowPaymentsAvailable)<span class="pay-method-badge pay-method-badge-ok">{{ $isFa ? 'فعال' : 'Active' }}</span>@else<span class="pay-method-badge pay-method-badge-off">{{ $isFa ? 'غیرفعال' : 'Off' }}</span>@endif
            </div>
            <p class="pay-method-desc">{{ $isFa ? 'پرداخت با رمزارز از طریق فاکتور امن. ارز و شبکه را خودتان انتخاب می‌کنید.' : 'Pay with crypto via a secure hosted invoice. Pick the coin and network yourself.' }}</p>
            @if($nowPaymentsAvailable)
                <form class="form-grid" action="{{ $safeRoute('app.wallet.deposit') }}" method="post" data-loading-form data-telegram-auth>@csrf<input type="hidden" name="provider" value="nowpayments"><div class="field"><label class="field-label required" for="crypto-amount">{{ $isFa ? 'مبلغ دلاری' : 'USD amount' }}</label><div class="input-wrap"><input class="input number ltr" id="crypto-amount" name="amount_usd" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" required value="{{ old('amount_usd') }}" placeholder="50.00" data-persian-digits data-amount-field><span class="input-suffix">USD</span></div><p class="field-help">{{ $isFa?'حداقل شارژ 5 دلار است. ارز و شبکه پرداخت را در فاکتور امن NOWPayments انتخاب می‌کنید.':'Minimum top-up is $5. Choose the payment currency and network inside the secure NOWPayments hosted invoice.' }}</p></div><button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ساخت فاکتور رمزارزی' : 'Create crypto invoice' }}</button></form>
            @else<div class="notice"><x-icon name="clock" /><p>{{ $isFa?'NOWPayments فعلاً غیرفعال است.':'NOWPayments is temporarily unavailable.' }}</p></div>@endif
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

<script>
// Convert Persian/Arabic digits → Latin digits in any field marked
// with [data-persian-digits] AS THE USER TYPES — in real time.
//
// IMPORTANT: <input type="number"> REFUSES to accept Persian/Arabic digit
// characters at all (the browser just drops them on the floor before any
// JS can see them). So the input MUST be type="text" with inputmode="decimal"
// for this script to work. We also accept Persian decimal separators (،/٫)
// and Arabic decimal separators (٫) and convert them to a dot.
(function () {
    var PERSIAN_DIGITS = /[\u06F0-\u06F9\u0660-\u0669]/g;
    var PERSIAN_SEPARATORS = /[\u066B\u066C\u060C]/g;  // Arabic decimal/group separator, Persian comma

    function toLatinDigits(s) {
        return String(s)
            .replace(PERSIAN_SEPARATORS, '.')              // Persian/Arabic decimal separators → dot
            .replace(PERSIAN_DIGITS, function (d) {
                var code = d.charCodeAt(0);
                // Persian range 0x06F0-0x06F9 → 0-9
                if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
                // Arabic range 0x0660-0x0669 → 0-9
                if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
                return d;
            });
    }

    function keepOnlyNumeric(s) {
        // After converting digits, strip anything that isn't a digit or dot.
        // This prevents stray Persian letters / spaces / etc. from lingering.
        return s.replace(/[^0-9.]/g, '');
    }

    function sanitize(value, allowDecimal) {
        allowDecimal = (allowDecimal !== false);  // default true (USD field)
        var converted = toLatinDigits(value);
        var cleaned = allowDecimal
            ? keepOnlyNumeric(converted)
            : converted.replace(/[^0-9]/g, '');  // integer-only (Toman field)
        if (allowDecimal) {
            // Collapse multiple dots into one (e.g. "1.2.3" → "1.23" is too aggressive,
            // so just keep the first dot).
            var parts = cleaned.split('.');
            if (parts.length > 2) {
                cleaned = parts[0] + '.' + parts.slice(1).join('');
            }
        }
        return cleaned;
    }

    document.querySelectorAll('input[data-persian-digits]').forEach(function (el) {
        var allowDecimal = !el.hasAttribute('data-amount-integer');
        // Run once on load in case the field was pre-filled with Persian digits.
        el.value = sanitize(el.value, allowDecimal);

        el.addEventListener('input', function () {
            var pos = el.selectionStart;
            var before = el.value;
            var after = sanitize(before, allowDecimal);
            if (before !== after) {
                el.value = after;
                // Restore cursor position. The length might have changed if
                // the user pasted Persian digits + a Persian separator (which
                // becomes a dot, so the count stays the same in most cases).
                try {
                    var newPos = Math.min(pos, after.length);
                    el.setSelectionRange(newPos, newPos);
                } catch (e) {}
            }
        });

        // Also sanitize on blur as a safety net.
        el.addEventListener('blur', function () {
            el.value = sanitize(el.value, allowDecimal);
        });
    });

    // Min validation for amount fields with a data-min attribute.
    document.querySelectorAll('input[data-amount-field]').forEach(function (el) {
        el.form && el.form.addEventListener('submit', function () {
            var allowDecimal = !el.hasAttribute('data-amount-integer');
            el.value = sanitize(el.value, allowDecimal);
        });
    });
})();
</script>
@endsection
