@extends('layouts.admin')

@section('title', app()->isLocale('fa') ? 'جزئیات تراکنش' : 'Transaction details')
@section('page-title', app()->isLocale('fa') ? 'جزئیات تراکنش' : 'Transaction details')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $intent = $transaction;
    $attempts = collect($intent->attempts)->sortByDesc('id');
    $formatDate = static function ($value) use ($isFa): string {
        if (!$value) return '—';
        $date = \Illuminate\Support\Carbon::parse($value);
        return $isFa ? \App\Support\PersianDate::format($date) : $date->timezone('UTC')->format('Y/m/d H:i:s');
    };
@endphp

<header class="page-header">
    <div>
        <div class="eyebrow number">#{{ $intent->public_id }}</div>
        <div class="cluster"><h1 class="page-title">{{ strtoupper((string) ($intent->provider ?? 'ledger')) }}</h1><x-status-chip :value="$intent->status" /></div>
        <p class="page-lead">{{ $isFa ? 'رکورد درگاه، تلاش‌های تأیید و اثر دفتر کل در یک نمای فقط‌خواندنی.' : 'Gateway record, verification attempts, and ledger impact in one read-only view.' }}</p>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-secondary" href="{{ route('admin.users.show', $intent->user) }}"><x-icon name="user" />{{ $isFa ? 'پرونده کاربر' : 'User record' }}</a>
        @if($intent->order)<a class="btn btn-secondary" href="{{ route('admin.orders.show', $intent->order) }}"><x-icon name="campaign" />{{ $isFa ? 'سفارش' : 'Order' }}</a>@endif
    </div>
</header>

<div class="two-column" style="align-items:start">
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مشخصات پرداخت' : 'Payment record' }}</h2></div><span class="quick-icon"><x-icon name="transaction" /></span></div>
        <dl class="definition-list">
            <div class="definition-row"><dt>{{ $isFa ? 'کاربر' : 'User' }}</dt><dd>{{ $intent->user->display_name }} <small class="muted ltr">{{ '@'.ltrim((string) $intent->user->telegram_username, '@') }}</small></dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'هدف' : 'Purpose' }}</dt><dd>{{ $intent->purpose->value }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'مبلغ' : 'Amount' }}</dt><dd class="number">{{ number_format(intdiv($intent->amount_minor, 10)) }} {{ $isFa ? 'تومان' : 'Toman' }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'مرجع پذیرنده' : 'Merchant reference' }}</dt><dd class="number ltr">{{ $intent->merchant_reference }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'ایجاد' : 'Created' }}</dt><dd class="number">{{ $formatDate($intent->created_at) }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'تأیید نهایی' : 'Verified' }}</dt><dd class="number">{{ $formatDate($intent->verified_at) }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'انقضا' : 'Expires' }}</dt><dd class="number">{{ $formatDate($intent->expires_at) }}</dd></div>
        </dl>
    </section>

    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'تلاش‌های درگاه' : 'Gateway attempts' }}</h2><p class="card-subtitle number">{{ $attempts->count() }}</p></div></div>
        @forelse($attempts as $attempt)
            <article class="card card-soft" style="padding:13px;margin-top:10px">
                <div class="cluster-between"><strong class="number ltr">{{ $attempt->provider_reference ?: '—' }}</strong><span class="number muted">{{ $formatDate($attempt->created_at) }}</span></div>
                <dl class="definition-list" style="margin-top:8px">
                    <div class="definition-row"><dt>Authority</dt><dd class="number ltr">{{ $attempt->authority ?: '—' }}</dd></div>
                    <div class="definition-row"><dt>Verify code</dt><dd class="number">{{ $attempt->verify_code ?: '—' }}</dd></div>
                    <div class="definition-row"><dt>{{ $isFa ? 'تأیید' : 'Verified' }}</dt><dd class="number">{{ $formatDate($attempt->verified_at) }}</dd></div>
                </dl>
            </article>
        @empty
            <x-empty-state icon="transaction" :description="__('ui.empty.data')" />
        @endforelse
    </section>
</div>

<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اثر دفتر کل' : 'Ledger impact' }}</h2><p class="card-subtitle">{{ $isFa ? 'ثبت‌ها تغییرناپذیر و به تفکیک بدهکار/بستانکار هستند.' : 'Immutable debit and credit postings.' }}</p></div></div>
    @forelse($ledgerTransactions as $ledger)
        <article class="card card-soft" style="padding:14px;margin-top:10px">
            <div class="cluster-between"><strong>{{ $ledger->description }}</strong><span class="number ltr">{{ $ledger->idempotency_key }}</span></div>
            <div class="table-wrap" style="margin-top:10px"><table class="data-table"><thead><tr><th>{{ $isFa ? 'حساب' : 'Account' }}</th><th>{{ $isFa ? 'جهت' : 'Direction' }}</th><th>{{ $isFa ? 'مبلغ' : 'Amount' }}</th></tr></thead><tbody>
            @foreach($ledger->entries as $entry)<tr><td>{{ $entry->account->name }}</td><td>{{ $entry->direction }}</td><td class="number">{{ number_format(intdiv($entry->amount_minor, 10)) }} {{ $isFa ? 'تومان' : 'Toman' }}</td></tr>@endforeach
            </tbody></table></div>
        </article>
    @empty
        <p class="muted">{{ $isFa ? 'برای این پرداخت هنوز ثبت دفتر کل وجود ندارد.' : 'No ledger posting exists for this payment yet.' }}</p>
    @endforelse
</section>

{{-- ─── Admin status change form ─────────────────────────────────────────
     Lets the admin manually change the payment intent's status:
       • Mark a held (manual_review) payment as Succeeded after the admin
         confirms the gateway receipt out-of-band — settles the ledger.
       • Mark a stuck Verifying payment as Failed when the user reports
         they never actually paid.
       • Move a payment back to manual_review for further investigation.
     All transitions are audited. Succeeded → other statuses are
     disallowed here (would un-credit the wallet); use the ledger
     adjustment flow for that. --}}
<section class="section card" aria-labelledby="admin-status-title">
    <div class="card-head"><div><h2 class="card-title" id="admin-status-title">{{ $isFa ? 'تغییر وضعیت تراکنش' : 'Change transaction status' }}</h2><p class="card-subtitle">{{ $isFa ? 'برای کاربر و ادمین ثبت می‌شود.' : 'Logged for both user and admin.' }}</p></div><x-icon name="edit" /></div>
    <form class="form-grid" method="post" action="{{ route('admin.transactions.status', $intent) }}">@csrf
        <div class="field-row">
            <div class="field"><label class="field-label required" for="status-new">{{ $isFa ? 'وضعیت جدید':'New status' }}</label>
                <select class="select" id="status-new" name="status" required>
                    @foreach(($paymentStatuses ?? ['created','pending','verifying','succeeded','failed','manual_review','expired','cancelled']) as $s)
                        <option value="{{ $s }}" @selected($s === ($intent->status instanceof \App\Enums\PaymentStatus ? $intent->status->value : (string) $intent->status))>{{ \Illuminate\Support\Facades\Lang::has('ui.status.'.$s) ? __('ui.status.'.$s) : str($s)->replace('_',' ')->headline() }}</option>
                    @endforeach
                </select>
                @error('status')<p class="field-error" style="color:#dc2626">{{ $message }}</p>@enderror
            </div>
            <div class="field"><label class="field-label" for="status-note">{{ $isFa ? 'یادداشت (دلیل تغییر)':'Note (reason)' }}</label>
                <input class="input" id="status-note" name="note" maxlength="500" placeholder="{{ $isFa ? 'مثلاً: رسید پرداخت در تایید شد':'e.g. Bank receipt confirmed' }}" value="{{ old('note') }}">
            </div>
        </div>
        <button class="btn btn-primary" type="submit" data-confirm="{{ $isFa ? 'آیا از تغییر وضعیت مطمئن هستید؟':'Are you sure you want to change the status?' }}">{{ $isFa ? 'اعمال تغییر وضعیت':'Apply status change' }}</button>
    </form>
    <p class="field-help" style="margin-top:8px">{{ $isFa ? 'تبدیل به «موفق» باعث ثبت سند دفتر کل و شارژ کیف پول کاربر می‌شود. تبدیل از «موفق» به وضعیت دیگر مجاز نیست — برای بازگردانی، از دفتر کل استفاده کنید.':'Transitioning to Succeeded settles the ledger and credits the user. Transitioning out of Succeeded is not allowed — use the ledger for reversal.' }}</p>
</section>
@endsection
