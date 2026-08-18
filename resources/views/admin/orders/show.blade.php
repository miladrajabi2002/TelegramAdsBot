@extends('layouts.admin')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static function (string $name, array $parameters = []): string {
        if (! \Illuminate\Support\Facades\Route::has($name)) return '#';
        try { return route($name, $parameters); } catch (\Throwable) { return '#'; }
    };
    $order = $order ?? null;
    $id = data_get($order, 'public_id', data_get($order, 'id'));
    $revision = data_get($order, 'currentRevision') ?: data_get($order, 'current_revision') ?: $currentRevision ?? null;
    $user = data_get($order, 'user') ?: $customer ?? null;
    $title = data_get($revision, 'internal_title') ?: ($isFa ? 'کمپین بدون عنوان' : 'Untitled campaign');
    $status = data_get($order, 'status', 'draft');
    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $targets = collect(data_get($revision, 'targets', $campaignTargets ?? []));
    $events = collect(data_get($order, 'statusEvents', data_get($order, 'status_events', $statusEvents ?? [])))->sortByDesc('created_at');
    $submission = $telegramSubmission ?? collect(data_get($revision, 'telegramSubmissions', []))->last();
    $metrics = collect(data_get($order, 'metrics', $metricSnapshots ?? []))->sortByDesc('as_of_at');
    $tasks = collect(data_get($order, 'operatorTasks', data_get($order, 'operator_tasks', [])));
    $reconciliationTask = $status === 'completed'
        ? $tasks->firstWhere('type', 'reconcile_completed_campaign')
        : $tasks->firstWhere('type', 'reconcile_telegram_rejection');
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \Illuminate\Support\Carbon::parse($value)->format('Y/m/d H:i'); } catch (\Throwable) { return (string) $value; } };
@endphp
@section('title', $title)
@section('page-title', $isFa ? 'جزئیات سفارش' : 'Order details')
@section('page-kicker', '#'.$id)

<header class="page-header"><div><div class="eyebrow number">#{{ $id ?: '—' }}</div><div class="cluster"><h1 class="page-title">{{ $title }}</h1><x-status-chip :value="$status" /></div><p class="page-lead">{{ $isFa ? 'تمام تصمیم‌ها، اثر مالی و پیام مشتری قبل از ثبت نمایش داده می‌شود.' : 'Review every decision, financial effect, and customer notification before applying it.' }}</p></div><div class="page-header-actions cluster"><a class="btn btn-secondary" href="{{ $safeRoute('admin.users.show',['user'=>data_get($user,'id')]) }}"><x-icon name="user" />{{ $isFa ? 'بررسی کاربر' : 'Inspect user' }}</a></div></header>

<div class="review-layout">
    <aside class="stack">
        @if(in_array($status, ['telegram_rejected', 'completed'], true))
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $status === 'completed' ? ($isFa?'تسویه نهایی کمپین':'Final campaign settlement') : ($isFa?'تطبیق مالی رد Telegram':'Telegram rejection reconciliation') }}</h2><p class="card-subtitle">{{ $isFa ? 'فقط هزینه قطعی‌شده در حساب Telegram را وارد کنید.' : 'Enter only the amount finally charged by Telegram.' }}</p></div><x-status-chip :value="data_get($reconciliationTask,'status','open')" /></div>
            @if(data_get($reconciliationTask,'status') === 'completed')
                <dl class="definition-list"><div class="definition-row"><dt>{{ $isFa?'کسر قطعی Telegram':'Final Telegram charge' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($reconciliationTask,'context.telegram_spent_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div><div class="definition-row"><dt>{{ $isFa?'اعتبار تبلیغاتی منتقل‌شده':'Restricted ad credit' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($reconciliationTask,'context.restricted_ad_credit_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div></dl>
            @elseif($reconciliationTask)
                <form class="form-grid" action="{{ $safeRoute($status === 'completed' ? 'admin.orders.reconcile-completion' : 'admin.orders.reconcile-rejection',['order'=>$id]) }}" method="post" data-loading-form>@csrf<div class="field"><label class="field-label required" for="telegram-spent">{{ $isFa?'مبلغ قطعی کسرشده از بودجه رسانه':'Final media-budget charge' }}</label><div class="input-with-suffix"><input class="input number" id="telegram-spent" name="telegram_spent_toman" type="number" min="0" max="{{ intdiv((int)data_get($order,'media_budget_irr',0),10) }}" required><span>{{ $isFa?'تومان':'Toman' }}</span></div></div><div class="field"><label class="field-label" for="reconcile-note">{{ $isFa?'مرجع/یادداشت تطبیق':'Reconciliation note' }}</label><textarea class="textarea" id="reconcile-note" name="note" maxlength="2000"></textarea></div><div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa?'مانده بودجه رسانه به اعتبار تبلیغاتی غیرقابل‌برداشت منتقل می‌شود؛ این عملیات قابل ویرایش نیست.':'Unspent media budget becomes non-withdrawable ad credit; this posting is immutable.' }}</p></div><button class="btn btn-danger btn-block" type="submit" data-confirm="{{ $isFa?'مبلغ را با حساب Telegram تطبیق داده‌اید؟':'Have you reconciled this amount with the Telegram account?' }}">{{ $isFa?'ثبت تطبیق نهایی':'Post final reconciliation' }}</button></form>
            @else
                <div class="notice"><x-icon name="check" /><p>{{ $isFa?'برای این سفارش مانده رزروشده‌ای جهت تسویه وجود ندارد.':'This order has no active reserved balance to reconcile.' }}</p></div>
            @endif
        </section>
        @endif
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مشتری' : 'Customer' }}</h2></div></div>
            <div class="user-hero"><span class="avatar avatar-lg">@if(data_get($user,'photo_url'))<img src="{{ data_get($user,'photo_url') }}" alt="">@else{{ mb_strtoupper(mb_substr((string)data_get($user,'display_name','U'),0,1)) }}@endif</span><div class="user-hero-copy"><h1 style="font-size:19px">{{ data_get($user,'display_name','—') }}</h1><div class="muted ltr">{{ data_get($user,'telegram_username') ? '@'.ltrim(data_get($user,'telegram_username'),'@') : data_get($user,'telegram_user_id','—') }}</div><div class="cluster" style="margin-top:8px"><x-status-chip :value="data_get($user,'kyc_level','base')" /><x-status-chip :value="data_get($user,'account_status','active')" /></div></div></div>
        </section>
        <section class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مالی' : 'Financials' }}</h2></div><x-status-chip :value="data_get($order,'payment_status','unfunded')" /></div><dl class="definition-list"><div class="definition-row"><dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'media_budget_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'کارمزد خدمات' : 'Service fee' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'service_fee_irr',0),10)) }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'کارمزد درگاه' : 'Gateway fee' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'gateway_fee_irr',0),10)) }}</dd></div><div class="definition-row"><dt><strong>{{ __('ui.common.total') }}</strong></dt><dd class="number"><strong>{{ number_format(intdiv((int)data_get($order,'total_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</strong></dd></div><div class="definition-row"><dt>GRAM</dt><dd class="number">{{ number_format((float)data_get($order,'gram_amount',0),3) }}</dd></div></dl></section>
    </aside>

    <div class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'محتوای تبلیغ' : 'Ad content' }}</h2><p class="card-subtitle">{{ $isFa ? 'نسخه فعلی قفل‌شده برای ثبت' : 'Current revision selected for submission' }}</p></div><span class="chip number">{{ mb_strlen((string)data_get($revision,'ad_text','')) }}/160</span></div>
            <dl class="definition-list"><div class="definition-row"><dt>{{ $isFa ? 'نوع مقصد' : 'Destination type' }}</dt><dd>{{ data_get($revision,'destination_type','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'لینک مقصد' : 'Destination' }}</dt><dd class="ltr">{{ data_get($revision,'destination_url','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'پلن / فرکانس' : 'Plan / frequency' }}</dt><dd>{{ data_get($revision,'plan','standard') }} · <span class="number">{{ data_get($revision,'frequency_cap','—') }}</span></dd></div><div class="definition-row"><dt>{{ $isFa ? 'هدف نمایش' : 'Impression goal' }}</dt><dd class="number">{{ number_format((int)data_get($revision,'impression_goal',0)) }}</dd></div></dl>
            <div class="card card-soft" style="margin-top:14px;padding:14px"><p style="margin:0;white-space:pre-wrap">{{ data_get($revision,'ad_text','—') }}</p></div>
        </section>

        <section class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'کانال‌های هدف' : 'Target channels' }}</h2><p class="card-subtitle number">{{ $targets->count() }}</p></div></div>@if($targets->isEmpty())<div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'هیچ کانال هدفی ثبت نشده است.' : 'No target channels are recorded.' }}</p></div>@else<div class="stack-sm">@foreach($targets as $target)<div class="cluster-between"><div class="table-primary"><span class="avatar"><x-icon name="channel" /></span><span class="table-primary-copy"><strong>{{ data_get($target,'channel_title',data_get($target,'channel_username','—')) }}</strong><small class="ltr">{{ '@'.ltrim((string)data_get($target,'channel_username',''),'@') }} · {{ number_format((int)data_get($target,'members_snapshot',0)) }}</small></span></div><x-status-chip :value="data_get($target,'validation_status','pending')" /></div>@endforeach</div>@endif</section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">Telegram Ads</h2><p class="card-subtitle">{{ $isFa ? 'ثبت شناسه و وضعیت تصمیم Telegram' : 'Record the external ID and Telegram decision' }}</p></div><x-status-chip :value="data_get($submission,'status','pending')" /></div>
            @if($status === 'queued_for_telegram')
                <form class="form-grid" action="{{ $safeRoute('admin.orders.telegram-submission',['order'=>$id]) }}" method="post" data-loading-form>@csrf
                    <div class="field-row"><div class="field"><label class="field-label required" for="external-ad-id">Telegram Ad ID</label><input class="input ltr" id="external-ad-id" name="external_ad_id" required maxlength="150" value="{{ old('external_ad_id') }}"></div><div class="field"><label class="field-label" for="telegram-account">{{ $isFa ? 'حساب تبلیغاتی' : 'Ads account' }}</label><input class="input" id="telegram-account" name="external_account_label" maxlength="150" value="{{ old('external_account_label') }}"></div></div>
                    <div class="field"><label class="field-label" for="telegram-submission-note">{{ $isFa?'یادداشت ثبت':'Submission note' }}</label><textarea class="textarea" id="telegram-submission-note" name="note" maxlength="2000">{{ old('note') }}</textarea></div>
                    <button class="btn btn-primary" type="submit"><x-icon name="send" />{{ $isFa?'ثبت شناسه و ارسال به بررسی Telegram':'Save ID and mark submitted' }}</button>
                </form>
            @elseif($status === 'telegram_review')
                <dl class="definition-list"><div class="definition-row"><dt>Telegram Ad ID</dt><dd class="number ltr">{{ data_get($submission,'external_ad_id','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'حساب تبلیغاتی':'Ads account' }}</dt><dd>{{ data_get($submission,'external_account_label','—') }}</dd></div></dl>
                <div class="two-column" style="margin-top:16px">
                    <form action="{{ $safeRoute('admin.orders.telegram-decision',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="decision" value="approved"><button class="btn btn-primary btn-block" type="submit"><x-icon name="check" />{{ $isFa?'تأیید Telegram':'Telegram approved' }}</button></form>
                    <form class="form-grid" action="{{ $safeRoute('admin.orders.telegram-decision',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="decision" value="rejected"><div class="field"><label class="field-label required" for="telegram-rejection-reason">{{ $isFa?'دلیل رد Telegram':'Telegram rejection reason' }}</label><textarea class="textarea" id="telegram-rejection-reason" name="rejection_reason" required maxlength="2000">{{ old('rejection_reason') }}</textarea></div><button class="btn btn-danger btn-block" type="submit"><x-icon name="warning" />{{ $isFa?'ثبت رد Telegram':'Record Telegram rejection' }}</button></form>
                </div>
            @else
                <div class="notice"><x-icon name="clock" /><p>{{ $isFa?'اقدام Telegram فقط در مرحله «آماده ثبت» یا «در حال بررسی Telegram» فعال است.':'Telegram actions are available only while queued for submission or under Telegram review.' }}</p></div>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'ثبت آمار دستی' : 'Record metric snapshot' }}</h2><p class="card-subtitle">{{ $isFa ? 'مقادیر تجمعی را از پنل Telegram وارد کنید.' : 'Enter cumulative values from Telegram Ads.' }}</p></div></div>
            <form class="form-grid" action="{{ $safeRoute('admin.orders.metrics.store',['order'=>$id]) }}" method="post" data-loading-form>@csrf
                <div class="field-row"><div class="field"><label class="field-label required" for="as-of">{{ $isFa ? 'زمان آمار' : 'As of' }}</label><input class="input number" id="as-of" name="as_of_at" type="datetime-local" required></div><div class="field"><label class="field-label required" for="impressions">{{ $isFa ? 'نمایش' : 'Impressions' }}</label><input class="input number" id="impressions" name="impressions" type="number" min="0" required></div></div>
                <div class="field-row"><div class="field"><label class="field-label" for="joins">{{ $isFa ? 'عضویت' : 'Joins' }}</label><input class="input number" id="joins" name="joins" type="number" min="0" value="0"></div><div class="field"><label class="field-label" for="bot-starts">{{ $isFa ? 'شروع ربات' : 'Bot starts' }}</label><input class="input number" id="bot-starts" name="bot_starts" type="number" min="0" value="0"></div></div>
                <div class="field-row"><div class="field"><label class="field-label required" for="spend-gram">Spend GRAM</label><input class="input number" id="spend-gram" name="spend_gram" type="number" min="0" step="0.000000001" required></div><div class="field"><label class="field-label" for="remaining-gram">Remaining GRAM</label><input class="input number" id="remaining-gram" name="remaining_budget_gram" type="number" min="0" step="0.000000001"></div></div>
                <button class="btn btn-primary" type="submit"><x-icon name="chart" />{{ $isFa ? 'ثبت Snapshot' : 'Save snapshot' }}</button>
            </form>
        </section>
    </div>

    <aside class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اقدام بعدی' : 'Next action' }}</h2><p class="card-subtitle"><x-status-chip :value="$status" /></p></div></div>
            <form class="form-grid" action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<div class="field"><label class="field-label" for="transition-note">{{ $isFa ? 'یادداشت و پیام مشتری' : 'Note and customer message' }}</label><textarea class="textarea" id="transition-note" name="note" maxlength="2000"></textarea></div><div class="stack-sm">
                @if($status === 'support_review')<button class="btn btn-primary btn-block" name="to_status" value="queued_for_telegram" type="submit"><x-icon name="check" />{{ $isFa ? 'تأیید پشتیبانی' : 'Approve support review' }}</button><button class="btn btn-danger btn-block" name="to_status" value="changes_requested" type="submit" data-require-field="#transition-note" data-required-message="{{ $isFa?'دلیل و روش اصلاح را برای مشتری بنویسید.':'Explain the reason and correction needed.' }}"><x-icon name="edit" />{{ $isFa ? 'رد و درخواست اصلاح' : 'Request changes' }}</button>@endif
                @if(in_array($status,['telegram_approved','scheduled'],true))<button class="btn btn-primary btn-block" name="to_status" value="active" type="submit"><x-icon name="play" />{{ $isFa ? 'شروع اجرا' : 'Mark running' }}</button>@endif
                @if($status === 'pause_requested')<button class="btn btn-secondary btn-block" name="to_status" value="paused" type="submit"><x-icon name="pause" />{{ $isFa ? 'تأیید توقف' : 'Confirm pause' }}</button>@endif
                @if($status === 'resume_requested')<button class="btn btn-primary btn-block" name="to_status" value="active" type="submit"><x-icon name="play" />{{ $isFa ? 'تأیید ادامه' : 'Confirm resume' }}</button>@endif
                @if($status === 'active')<button class="btn btn-tonal btn-block" name="to_status" value="completed" type="submit"><x-icon name="check" />{{ $isFa ? 'پایان کمپین' : 'Complete campaign' }}</button>@endif
                @if(!in_array($status,['support_review','telegram_approved','scheduled','pause_requested','resume_requested','active'],true))<div class="notice"><x-icon name="clock" /><p>{{ $isFa ? 'در این وضعیت، اقدام مستقیم دیگری تعریف نشده است.' : 'No direct lifecycle action is available in this state.' }}</p></div>@endif
            </div></form>
        </section>
        <section class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'تاریخچه' : 'History' }}</h2></div></div>@if($events->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<ul class="timeline">@foreach($events->take(12) as $event)<li class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ \Illuminate\Support\Facades\Lang::has('ui.status.'.data_get($event,'to_status')) ? __('ui.status.'.data_get($event,'to_status')) : data_get($event,'to_status') }}</strong>@if(data_get($event,'note'))<span>{{ data_get($event,'note') }}</span>@endif<small class="number">{{ $formatDate(data_get($event,'created_at')) }}</small></span></li>@endforeach</ul>@endif</section>
    </aside>
</div>
@endsection
