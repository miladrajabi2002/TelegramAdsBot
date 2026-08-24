@extends('layouts.admin')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static function (string $name, array $parameters = []): string {
        if (! \Illuminate\Support\Facades\Route::has($name)) return '#';
        try { return route($name, $parameters); } catch (\Throwable) { return '#'; }
    };
    $user = $user ?? null;
    $id = data_get($user,'id');
    $name = data_get($user,'display_name',$isFa?'کاربر':'User');
    $orders = collect($orders ?? data_get($user,'orders',[]))->take(8);
    $transactions = collect($transactions ?? [])->take(8);
    $tickets = collect($tickets ?? [])->take(6);
    $activities = collect($activities ?? $auditLogs ?? [])->take(12);
    $cards = collect($fundingCards ?? data_get($user,'fundingCards',[]))
        ->sortByDesc(fn ($card) => (data_get($card,'status') === 'approved' ? 2_000_000_000 : 0) + (int) optional(data_get($card,'updated_at'))->timestamp);
    $kycLevel = data_get($user,'kyc_level','base');
    $kycLevel = $kycLevel instanceof \BackedEnum ? $kycLevel->value : (string) $kycLevel;
    $canReplaceCard = $kycLevel === 'rial_verified' && (bool) auth('admin')->user()?->hasPermission('kyc.review');
    $defaultHolderName = (string) (data_get($cards->firstWhere('status','approved'),'holder_name_encrypted')
        ?: data_get($user,'kycApplications.0.legal_name_encrypted',''));
    $riskFlags = collect(data_get($user,'risk_flags',[]));
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); } catch (\Throwable) { return (string)$value; } };
@endphp
@section('title', $name)
@section('page-title', $isFa?'پرونده کاربر':'User record')
@section('page-kicker', 'Telegram ID '.data_get($user,'telegram_user_id','—'))

<section class="card">
    <div class="user-hero"><span class="avatar avatar-lg">@if(data_get($user,'id'))<img src="@avatarUrl($user)" alt="" decoding="async" loading="lazy" onerror="this.style.display='none'; this.parentElement.classList.add('avatar-fallback')"><span class="avatar-initial" aria-hidden="true" style="display:none">{{ mb_strtoupper(mb_substr($name,0,1)) }}</span>@else{{ mb_strtoupper(mb_substr($name,0,1)) }}@endif</span><div class="user-hero-copy"><div class="cluster"><h1>{{ $name }}</h1><x-status-chip :value="data_get($user,'account_status','active')" /></div><div class="muted ltr">{{ data_get($user,'telegram_username') ? '@'.ltrim(data_get($user,'telegram_username'),'@') : 'Telegram ID '.data_get($user,'telegram_user_id','—') }}</div><div class="cluster" style="margin-top:9px"><x-status-chip :value="data_get($user,'kyc_level','base')" />@foreach($riskFlags as $flag)<span class="status-chip status-warning"><x-icon name="warning" size="sm" />{{ is_array($flag)?data_get($flag,'label',data_get($flag,'code','risk')):$flag }}</span>@endforeach</div></div><div class="cluster"><a class="btn btn-secondary" href="{{ $safeRoute('admin.support.index',['user_id'=>$id]) }}"><x-icon name="support" />{{ $isFa?'پیام به کاربر':'Message user' }}</a><form action="{{ $safeRoute('admin.users.refresh-photo',['user'=>$id]) }}" method="post" style="display:inline">@csrf<button class="btn btn-secondary" type="submit" data-loading-form title="{{ $isFa?'دریافت آدرس تازه عکس پروفایل از تلگرام' : 'Re-fetch profile photo URL from Telegram' }}"><x-icon name="refresh" />{{ $isFa?'تازه‌سازی عکس':'Refresh photo' }}</button></form></div></div>
</section>

<nav class="tabs section" aria-label="{{ $isFa?'بخش‌های پرونده':'Record sections' }}"><a class="tab-link is-active" href="#overview">{{ $isFa?'نمای کلی':'Overview' }}</a><a class="tab-link" href="#identity">{{ $isFa?'هویت':'Identity' }}</a><a class="tab-link" href="#orders">{{ $isFa?'سفارش‌ها':'Orders' }}</a><a class="tab-link" href="#transactions">{{ $isFa?'تراکنش‌ها':'Transactions' }}</a><a class="tab-link" href="#activity">{{ $isFa?'فعالیت':'Activity' }}</a></nav>

<section class="section" id="overview"><div class="metric-grid"><div class="metric"><div class="metric-label">{{ $isFa?'موجودی قابل‌استفاده':'Available balance' }}</div><div class="metric-value number">{{ number_format(intdiv((int)($availableBalanceIrr ?? 0),10)) }}</div><div class="metric-delta">{{ $isFa?'تومان':'Toman' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'موجودی رزروشده':'Held balance' }}</div><div class="metric-value number">{{ number_format(intdiv((int)($heldBalanceIrr ?? 0),10)) }}</div><div class="metric-delta">{{ $isFa?'تومان':'Toman' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'کل خرید تأمین‌شده':'Lifetime funded value' }}</div><div class="metric-value number">{{ number_format(intdiv((int)($lifetimeSpendIrr ?? 0),10)) }}</div><div class="metric-delta">{{ $isFa?'تومان':'Toman' }}</div></div><div class="metric"><div class="metric-label">{{ $isFa?'تعداد سفارش':'Orders' }}</div><div class="metric-value number">{{ data_get($user,'orders_count',$orders->count()) }}</div><div class="metric-delta">{{ $isFa?'همه وضعیت‌ها':'All statuses' }}</div></div></div></section>

<div class="two-column section" id="identity" style="align-items:start">
    <section class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'اطلاعات حساب':'Account details' }}</h2></div></div><dl class="definition-list"><div class="definition-row"><dt>{{ $isFa?'تلفن':'Phone' }}</dt><dd class="number ltr">{{ data_get($user,'phone','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'تأیید تلفن':'Phone verified' }}</dt><dd>{{ data_get($user,'phone_verified_at') ? ($isFa?'بله':'Yes') : ($isFa?'خیر':'No') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'عضویت':'Joined' }}</dt><dd class="number">{{ $formatDate(data_get($user,'created_at')) }}</dd></div><div class="definition-row"><dt>{{ $isFa?'آخرین فعالیت':'Last active' }}</dt><dd class="number">{{ $formatDate(data_get($user,'last_seen_at')) }}</dd></div></dl></section>
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa?'کارت‌های بانکی':'Bank cards' }}</h2><p class="card-subtitle">{{ $isFa?'کارت فعال و تمام کارت‌های قبلی؛ فقط اطلاعات ماسک‌شده نمایش داده می‌شود.':'Active card and complete previous-card history; masked data only.' }}</p></div></div>
        <div class="stack-sm">
            @forelse($cards as $card)
                <div class="option-card">
                    <span class="quick-icon"><x-icon name="card" /></span>
                    <span class="option-card-copy">
                        <strong class="number ltr">{{ data_get($card,'bin','••••••') }} •••••• {{ data_get($card,'last4','••••') }}</strong>
                        <small>{{ data_get($card,'holder_name_search','—') }} · {{ $formatDate(data_get($card,'verified_at',data_get($card,'created_at'))) }}</small>
                    </span>
                    <x-status-chip :value="data_get($card,'status','pending')" />
                </div>
            @empty
                <p class="muted">{{ $isFa?'کارت ثبت نشده است.':'No card recorded.' }}</p>
            @endforelse
        </div>

        @if($canReplaceCard)
            <hr class="divider">
            <div class="card-head"><div><h3 class="card-title" style="font-size:16px">{{ $isFa?'تعویض کارت فعال':'Replace active card' }}</h3><p class="card-subtitle">{{ $isFa?'تعویض اتمیک است: کارت قبلی غیرفعال و کارت جدید هم‌زمان فعال می‌شود؛ همیشه یک کارت فعال باقی می‌ماند.':'Replacement is atomic: the old card is deactivated as the new one becomes active, so one active card always remains.' }}</p></div></div>
            <form class="form-grid" action="{{ $safeRoute('admin.users.funding-card.replace',['user'=>$id]) }}" method="post" data-loading-form>
                @csrf
                <div class="field"><label class="field-label required" for="replacement-card-number">{{ $isFa?'شماره کارت جدید':'New card number' }}</label><input class="input number ltr" id="replacement-card-number" name="card_number" inputmode="numeric" autocomplete="off" maxlength="30" required value="{{ old('card_number') }}" placeholder="0000 0000 0000 0000">@error('card_number')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="field"><label class="field-label required" for="replacement-holder-name">{{ $isFa?'نام صاحب کارت':'Card holder name' }}</label><input class="input" id="replacement-holder-name" name="holder_name" maxlength="120" required value="{{ old('holder_name',$defaultHolderName) }}"></div>
                <div class="field"><label class="field-label required" for="replacement-reason">{{ $isFa?'دلیل تعویض':'Replacement reason' }}</label><textarea class="textarea" id="replacement-reason" name="reason" maxlength="1000" required>{{ old('reason') }}</textarea></div>
                <label class="checkbox"><input type="checkbox" name="confirmed" value="1" required><span>{{ $isFa?'مالکیت کارت جدید را با هویت تأییدشده تطبیق دادم.':'I verified that the new card belongs to the approved identity.' }}</span></label>
                <button class="btn btn-warning btn-block" type="submit" data-confirm="{{ $isFa?'کارت فعلی غیرفعال و کارت جدید فعال شود؟':'Deactivate the current card and activate the new card?' }}"><x-icon name="refresh" />{{ $isFa?'تعویض کارت فعال':'Replace active card' }}</button>
            </form>
        @endif
    </section>
</div>

<section class="section card" id="orders"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'سفارش‌ها':'Orders' }}</h2></div><a class="btn btn-text btn-sm" href="{{ $safeRoute('admin.orders.index',['user_id'=>$id]) }}">{{ $isFa?'مشاهده همه':'View all' }}</a></div>@if($orders->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<div class="table-wrap"><table class="data-table"><thead><tr><th>{{ __('ui.common.order') }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($orders as $order)@php($orderId=data_get($order,'public_id',data_get($order,'id')))<tr><td data-label="{{ __('ui.common.order') }}"><a href="{{ $safeRoute('admin.orders.show',['order'=>$orderId]) }}"><strong>{{ data_get($order,'currentRevision.internal_title',data_get($order,'current_revision.internal_title','#'.$orderId)) }}</strong></a></td><td data-label="{{ __('ui.common.amount') }}" class="number">{{ number_format(intdiv((int)data_get($order,'total_irr',0),10)) }}</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($order,'status','draft')" /></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($order,'created_at')) }}</td></tr>@endforeach</tbody></table></div>@endif</section>

<section class="section card" id="transactions"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'دفتر کل و تراکنش‌ها':'Ledger and transactions' }}</h2></div><a class="btn btn-text btn-sm" href="{{ $safeRoute('admin.transactions.index',['user_id'=>$id]) }}">{{ $isFa?'مشاهده همه':'View all' }}</a></div>@if($transactions->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<div class="table-wrap"><table class="data-table"><thead><tr><th>{{ $isFa?'شرح':'Description' }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($transactions as $transaction)<tr><td data-label="{{ $isFa?'شرح':'Description' }}">{{ data_get($transaction,'description',data_get($transaction,'type','—')) }}</td><td data-label="{{ __('ui.common.amount') }}" class="number">{{ number_format(intdiv((int)data_get($transaction,'amount_minor',0),10)) }}</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($transaction,'status','verified')" /></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($transaction,'created_at')) }}</td></tr>@endforeach</tbody></table></div>@endif</section>

<section class="section card" id="activity"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'تاریخچه فعالیت':'Activity history' }}</h2><p class="card-subtitle">{{ $isFa?'اقدامات کاربر و مدیران':'User and administrator actions' }}</p></div></div>@if($activities->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<ul class="timeline">@foreach($activities as $activity)<li class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ data_get($activity,'action','—') }}</strong>@if(data_get($activity,'action') === 'kyc.funding_card_replaced')<span class="number ltr">**** {{ collect(data_get($activity,'before_redacted.approved_cards',[]))->pluck('last4')->filter()->join(', **** ') ?: '—' }} → **** {{ data_get($activity,'after_redacted.approved_card_last4','—') }}</span>@endif @if(data_get($activity,'reason'))<span>{{ data_get($activity,'reason') }}</span>@endif<small class="number">{{ $formatDate(data_get($activity,'created_at')) }}</small></span></li>@endforeach</ul>@endif</section>
@endsection
