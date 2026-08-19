@extends('layouts.admin')

@section('title', __('ui.admin_nav.transactions'))
@section('page-title', __('ui.admin_nav.transactions'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $transactions ?? $paymentIntents ?? collect();
    $source = is_array($source) ? collect($source) : $source;
    $items = collect(is_object($source) && method_exists($source,'items') ? $source->items() : $source);
    $journalSource = $ledgerTransactions ?? collect();
    $journals = collect(is_object($journalSource) && method_exists($journalSource, 'items') ? $journalSource->items() : $journalSource);
    $payoutSource = $payouts ?? collect();
    $payoutItems = collect(is_object($payoutSource) && method_exists($payoutSource, 'items') ? $payoutSource->items() : $payoutSource);
    $summary = $stats ?? [];
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); } catch (\Throwable) { return (string)$value; } };

    // ─── Safe enum/string normalizer ─────────────────────────────────
    // Several model columns are cast to PHP enums (e.g. PaymentIntent->purpose
    // is a PaymentPurpose enum). Casting an enum to (string) without calling
    // ->value throws "Object of class App\Enums\X could not be converted to
    // string" — which is exactly the production error that broke the
    // transactions page on 2026-08-19.
    //
    // This helper accepts any of:
    //   - a string (returned as-is)
    //   - a UnitEnum instance (returns ->value)
    //   - a Model object with a ->value attribute (returns that attribute)
    //   - null / empty string (returns the fallback)
    //
    // It is used everywhere we render `purpose`, `type`, `provider`, etc.
    // in this view.
    $enumValue = static function (mixed $value, string $fallback = ''): string {
        if ($value === null || $value === '') return $fallback;
        if (is_string($value)) return $value;
        if (is_int($value)) return (string) $value;
        if ($value instanceof \UnitEnum) {
            $case = $value->value ?? $value->name ?? null;
            return is_scalar($case) ? (string) $case : ($value->name ?? $fallback);
        }
        // Eloquent models / stdClass with a ->value attribute.
        if (is_object($value)) {
            if (isset($value->value) && is_scalar($value->value)) return (string) $value->value;
            if (method_exists($value, '__toString')) return (string) $value;
        }
        return $fallback;
    };
    $humanize = static function (mixed $value, string $fallback = '') use ($enumValue): string {
        $str = $enumValue($value, $fallback);
        if ($str === '') return $fallback;
        return str($str)->replace('_', ' ')->title()->toString();
    };
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa?'تطبیق پرداخت و دفتر کل':'Payment and ledger reconciliation' }}</div><h1 class="page-title">{{ __('ui.admin_nav.transactions') }}</h1><p class="page-lead">{{ $isFa?'واریز، برداشت، رزرو، آزادسازی و مغایرت‌های درگاه را بدون ویرایش رکورد اصلی بررسی کنید.':'Review deposits, withdrawals, holds, releases, and gateway mismatches without mutating source records.' }}</p></div></header>
<div class="metric-grid"><div class="metric"><div class="metric-label">{{ $isFa?'واریز تأییدشده امروز':'Verified deposits today' }}</div><div class="metric-value number">{{ number_format(intdiv((int)data_get($summary,'verified_deposits_irr',0),10)) }}</div><div class="metric-delta">{{ $isFa?'تومان':'Toman' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'پرداخت معلق':'Held payments' }}</div><div class="metric-value number">{{ number_format((int)data_get($summary,'held_count',0)) }}</div><div class="metric-delta">{{ $isFa?'نیازمند بررسی':'Needs review' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'پرداخت ناموفق':'Failed payments' }}</div><div class="metric-value number">{{ number_format((int)data_get($summary,'failed_count',0)) }}</div><div class="metric-delta">{{ $isFa?'30 روز اخیر':'Last 30 days' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'بدهی کیف پول':'Wallet liability' }}</div><div class="metric-value number">{{ number_format(intdiv((int)data_get($summary,'wallet_liability_irr',0),10)) }}</div><div class="metric-delta">{{ $isFa?'تومان':'Toman' }}</div></div></div>

{{-- ─── Admin wallet top-up form ───────────────────────────────────────
     Lets an admin manually credit a user's wallet. Useful for:
       • Reimbursing a user whose payment failed but the bank confirmed
         the deduction.
       • Promotional / goodwill credits.
       • Fixing a botched settlement from a gateway bug.
     The action is fully audited via the AuditLogger and the resulting
     PaymentIntent is marked with provider=admin_adjustment so reports
     can filter admin credits out of "real revenue". --}}
<section class="section card" aria-labelledby="admin-topup-title">
    <div class="card-head"><div><h2 class="card-title" id="admin-topup-title">{{ $isFa?'افزایش موجودی دستی کاربر':'Manual user wallet top-up' }}</h2><p class="card-subtitle">{{ $isFa?'مبلغ به تومان وارد کنید. کیف پول کاربر بلافاصله شارژ می‌شود.':'Enter amount in Toman. The user wallet is credited instantly.' }}</p></div><x-icon name="plus" /></div>
    <form class="form-grid" method="post" action="{{ $safeRoute('admin.transactions.topup') }}">@csrf
        <div class="field-row">
            <div class="field"><label class="field-label required" for="topup-user">{{ $isFa?'کاربر':'User' }}</label>
                <select class="select" id="topup-user" name="user_id" required>
                    <option value="">{{ $isFa?'انتخاب کاربر':'Select user' }}</option>
                    @foreach(($users ?? collect()) as $u)
                        <option value="{{ data_get($u,'id') }}" @selected((string) old('user_id') === (string) data_get($u,'id'))>#{{ data_get($u,'id') }} — {{ data_get($u,'display_name') ?: data_get($u,'telegram_username') ?: data_get($u,'telegram_user_id') }}</option>
                    @endforeach
                </select>
                @error('user_id')<p class="field-error" style="color:#dc2626">{{ $message }}</p>@enderror
            </div>
            <div class="field"><label class="field-label required" for="topup-amount">{{ $isFa?'مبلغ (تومان)':'Amount (Toman)' }}</label>
                <input class="input number ltr" id="topup-amount" name="amount_toman" type="text" inputmode="numeric" required value="{{ old('amount_toman') }}" placeholder="200000" min="1000" data-persian-digits data-amount-field data-amount-integer>
                @error('amount_toman')<p class="field-error" style="color:#dc2626">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="field"><label class="field-label" for="topup-note">{{ $isFa?'یادداشت (دلیل شارژ دستی)':'Note (reason for manual credit)' }}</label>
            <textarea class="input" id="topup-note" name="note" rows="2" maxlength="500">{{ old('note') }}</textarea>
            <p class="field-help">{{ $isFa?'این یادداشت در لاگ حسابرسی ثبت می‌شود.':'Recorded in the audit log.' }}</p>
        </div>
        <button class="btn btn-primary" type="submit">{{ $isFa?'افزایش موجودی':'Top up wallet' }}</button>
    </form>
</section>

<form class="filters section" method="get" action="{{ $safeRoute('admin.transactions.index') }}"><div class="field field-search"><label class="field-label" for="transaction-q">{{ __('ui.actions.search') }}</label><input class="input" id="transaction-q" name="q" value="{{ request('q') }}" placeholder="{{ $isFa?'شناسه، کاربر، مرجع درگاه یا چهار رقم کارت':'ID, user, provider reference, or card last four' }}"></div><div class="field"><label class="field-label" for="provider">{{ __('ui.common.method') }}</label><select class="select" id="provider" name="provider"><option value="">{{ __('ui.common.all') }}</option><option value="zarinpay" @selected(request('provider')==='zarinpay')>ZarinPay</option><option value="nowpayments" @selected(request('provider')==='nowpayments')>NOWPayments</option><option value="wallet" @selected(request('provider')==='wallet')>Wallet</option><option value="admin_adjustment" @selected(request('provider')==='admin_adjustment')>{{ $isFa?'تعدیل ادمین':'Admin adjustment' }}</option></select></div><div class="field"><label class="field-label" for="transaction-status">{{ __('ui.common.status') }}</label><select class="select" id="transaction-status" name="status"><option value="">{{ __('ui.common.all') }}</option>@foreach(['created','pending','verifying','succeeded','failed','manual_review','expired','cancelled','partially_refunded','refunded','chargeback'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ \Illuminate\Support\Facades\Lang::has('ui.status.'.$status)?__('ui.status.'.$status):str($status)->replace('_',' ')->headline() }}</option>@endforeach</select></div><button class="btn btn-secondary" type="submit"><x-icon name="filter" />{{ __('ui.actions.filter') }}</button></form>
@if($items->isEmpty())<x-empty-state icon="transaction" :description="__('ui.empty.data')" />@else<div class="table-wrap"><table class="data-table"><thead><tr><th>{{ $isFa?'تراکنش':'Transaction' }}</th><th>{{ __('ui.common.user') }}</th><th>{{ __('ui.common.method') }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead><tbody>@foreach($items as $transaction)@php($id=data_get($transaction,'public_id',data_get($transaction,'id')))<tr><td data-label="{{ $isFa?'تراکنش':'Transaction' }}"><div class="table-primary"><span class="quick-icon"><x-icon name="transaction" /></span><span class="table-primary-copy"><strong>{{ data_get($transaction,'description',$humanize(data_get($transaction,'purpose',data_get($transaction,'type','payment')), $isFa?'پرداخت':'Payment')) }}</strong><small class="number">#{{ $id }}</small></span></div></td><td data-label="{{ __('ui.common.user') }}"><a href="{{ $safeRoute('admin.users.show',['user'=>data_get($transaction,'user.id',data_get($transaction,'user_id'))]) }}">{{ data_get($transaction,'user.display_name','—') }}</a></td><td data-label="{{ __('ui.common.method') }}">{{ strtoupper($enumValue(data_get($transaction,'provider'),'ledger')) }}</td><td data-label="{{ __('ui.common.amount') }}" class="number">{{ number_format((int)data_get($transaction,'amount_minor',0)) }} {{ strtoupper($enumValue(data_get($transaction,'currency'),'IRR')) }}</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($transaction,'status','verified')" /></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($transaction,'created_at')) }}</td><td data-label="{{ __('ui.common.actions') }}"><div class="table-actions"><a class="btn btn-sm btn-secondary" href="{{ $safeRoute('admin.transactions.show',['transaction'=>$id]) }}">{{ __('ui.actions.details') }}</a></div></td></tr>@endforeach</tbody></table></div>@if(method_exists($source,'links'))<div class="pagination">{{ $source->links() }}</div>@endif @endif

<section class="section" aria-labelledby="ledger-title"><div class="section-heading"><div><h2 id="ledger-title">{{ $isFa?'دفتر کل تغییرناپذیر':'Immutable ledger journals' }}</h2><p class="muted">{{ $isFa?'هر رزرو، تسویه و اعتبار حداقل یک سند متوازن دارد.':'Every hold, settlement, and credit is backed by a balanced journal.' }}</p></div></div>@if($journals->isEmpty())<x-empty-state icon="document" :description="__('ui.empty.data')" />@else<div class="table-wrap"><table class="data-table"><thead><tr><th>{{ $isFa?'سند':'Journal' }}</th><th>{{ $isFa?'شرح':'Description' }}</th><th>{{ $isFa?'جمع بدهکار':'Total debits' }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($journals as $journal)@php($debits=collect(data_get($journal,'entries',[]))->where('direction','debit')->sum('amount_minor'))<tr><td data-label="{{ $isFa?'سند':'Journal' }}"><strong>{{ $humanize(data_get($journal,'type','journal'), $isFa?'دفتر کل':'Journal') }}</strong><small class="number" style="display:block">#{{ data_get($journal,'public_id',data_get($journal,'id')) }}</small></td><td data-label="{{ $isFa?'شرح':'Description' }}">{{ data_get($journal,'description','—') }}</td><td data-label="{{ $isFa?'جمع بدهکار':'Total debits' }}" class="number">{{ number_format((int)$debits) }} IRR</td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($journal,'created_at')) }}</td></tr>@endforeach</tbody></table></div>@if(method_exists($journalSource,'links'))<div class="pagination">{{ $journalSource->links() }}</div>@endif @endif</section>

<section class="section" aria-labelledby="payout-title"><div class="section-heading"><div><h2 id="payout-title">{{ $isFa?'برداشت و بازپرداخت':'Payout and refund requests' }}</h2><p class="muted">{{ $isFa?'درخواست‌های خروج وجه جدا از اعتبار تبلیغاتی غیرقابل‌برداشت ثبت می‌شوند.':'Cash outflows are tracked separately from non-withdrawable ad credit.' }}</p></div></div>@if($payoutItems->isEmpty())<x-empty-state icon="wallet" :description="__('ui.empty.data')" />@else<div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>{{ __('ui.common.user') }}</th><th>{{ $isFa?'نوع':'Type' }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($payoutItems as $payout)<tr><td class="number">#{{ data_get($payout,'id') }}</td><td><a href="{{ $safeRoute('admin.users.show',['user'=>data_get($payout,'user_id')]) }}">{{ data_get($payout,'user.display_name','—') }}</a></td><td>{{ $humanize(data_get($payout,'type','refund'), $isFa?'بازپرداخت':'Refund') }}</td><td class="number">{{ number_format((int)data_get($payout,'amount_minor',0)) }} {{ data_get($payout,'currency','IRR') }}</td><td><x-status-chip :value="data_get($payout,'status','requested')" /></td><td class="number">{{ $formatDate(data_get($payout,'created_at')) }}</td></tr>@endforeach</tbody></table></div>@if(method_exists($payoutSource,'links'))<div class="pagination">{{ $payoutSource->links() }}</div>@endif @endif</section>
@endsection
