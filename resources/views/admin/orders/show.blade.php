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
    $placement = (string) data_get($revision, 'placement_type', 'channel_posts');
    $placementLabel = [
        'channel_posts' => $isFa ? 'کانال‌ها' : 'Channels',
        'bot_messages' => $isFa ? 'ربات‌ها' : 'Bots',
        'search_results' => $isFa ? 'جستجو' : 'Search',
    ][$placement] ?? $placement;
    $targetLabel = $placement === 'bot_messages'
        ? ($isFa ? 'ربات‌های هدف' : 'Target bots')
        : ($isFa ? 'کانال‌های هدف' : 'Target channels');
    $targets = collect(data_get($revision, 'targets', $campaignTargets ?? []));
    // Pending/eligible targets are approved automatically with the first
    // support approval. Only an explicitly rejected/ineligible/unknown target
    // should block the order-level approval action.
    $blockingTargets = $targets->filter(fn ($target) => ! in_array(
        (string) data_get($target, 'validation_status', 'pending'),
        ['pending', 'eligible', 'approved'],
        true,
    ));
    $events = collect(data_get($order, 'statusEvents', data_get($order, 'status_events', $statusEvents ?? [])))->sortByDesc('created_at');
    $submission = $telegramSubmission ?? collect(data_get($revision, 'telegramSubmissions', []))->sortByDesc('id')->first();
    $metrics = collect(data_get($order, 'metrics', $metricSnapshots ?? []))->sortByDesc('as_of_at');
    $latestMetric = $metrics->first() ?: null;
    $tasks = collect(data_get($order, 'operatorTasks', data_get($order, 'operator_tasks', [])));
    $reconciliationTask = $status === 'completed'
        ? $tasks->where('type', 'reconcile_completed_campaign')->sortByDesc('id')->first()
        : $tasks->where('type', 'reconcile_telegram_rejection')->sortByDesc('id')->first();
    $formatDate = static function ($value): string {
        if (!$value) return '—';
        try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); }
        catch (\Throwable) { return (string) $value; }
    };
@endphp
@section('title', $title)
@section('page-title', $isFa ? 'جزئیات سفارش' : 'Order details')
@section('page-kicker', '#'.$id)

<header class="page-header">
    <div>
        <div class="eyebrow number">#{{ $id ?: '—' }}</div>
        <div class="cluster"><h1 class="page-title">{{ $title }}</h1><x-status-chip :value="$status" /></div>
        <p class="page-lead">{{ $isFa ? 'بررسی محتوا، هدف‌ها، وضعیت Telegram، آمار و اثر مالی سفارش.' : 'Review content, targets, Telegram status, metrics and financial impact.' }}</p>
    </div>
    <div class="page-header-actions cluster"><a class="btn btn-secondary" href="{{ $safeRoute('admin.users.show',['user'=>data_get($user,'id')]) }}"><x-icon name="user" />{{ $isFa ? 'بررسی کاربر' : 'Inspect user' }}</a></div>
</header>

<div class="review-layout">
    <aside class="stack">
        @if(in_array($status, ['telegram_rejected', 'completed'], true))
        <section class="card">
            <div class="card-head">
                <div><h2 class="card-title">{{ $status === 'completed' ? ($isFa?'تسویه نهایی کمپین':'Final campaign settlement') : ($isFa?'تطبیق مالی رد Telegram':'Telegram rejection reconciliation') }}</h2><p class="card-subtitle">{{ $isFa ? 'فقط هزینه قطعی‌شده در حساب Telegram را وارد کنید.' : 'Enter only the amount finally charged by Telegram.' }}</p></div>
                @if($reconciliationTask)<x-status-chip :value="data_get($reconciliationTask,'status','open')" />@endif
            </div>
            @if(data_get($reconciliationTask,'status') === 'completed')
                <dl class="definition-list">
                    <div class="definition-row"><dt>{{ $isFa?'کسر قطعی Telegram':'Final Telegram charge' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($reconciliationTask,'context.telegram_spent_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div>
                    <div class="definition-row"><dt>{{ $isFa?'اعتبار تبلیغاتی منتقل‌شده':'Restricted ad credit' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($reconciliationTask,'context.restricted_ad_credit_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div>
                </dl>
            @elseif(data_get($reconciliationTask,'status') === 'open')
                <form class="form-grid" action="{{ $safeRoute($status === 'completed' ? 'admin.orders.reconcile-completion' : 'admin.orders.reconcile-rejection',['order'=>$id]) }}" method="post" data-loading-form>@csrf
                    <div class="field"><label class="field-label required" for="telegram-spent">{{ $isFa?'مبلغ قطعی کسرشده از بودجه رسانه':'Final media-budget charge' }}</label><div class="input-with-suffix"><input class="input number" id="telegram-spent" name="telegram_spent_toman" type="number" min="0" max="{{ intdiv((int)data_get($order,'media_budget_irr',0),10) }}" required><span>{{ $isFa?'تومان':'Toman' }}</span></div></div>
                    <div class="field"><label class="field-label" for="reconcile-note">{{ $isFa?'مرجع/یادداشت تطبیق':'Reconciliation note' }}</label><textarea class="textarea" id="reconcile-note" name="note" maxlength="2000"></textarea></div>
                    @if($status === 'telegram_rejected')
                        <div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'اگر قرار است سفارش برای اصلاح مجدد به کاربر برگردد، اول از بخش «اقدام بعدی» مسیر اصلاح را انتخاب کنید و تطبیق مالی را نهایی نکنید.' : 'If the ad will be corrected and retried, return it for correction instead of finalizing reconciliation.' }}</p></div>
                    @endif
                    <button class="btn btn-danger btn-block" type="submit" data-confirm="{{ $isFa?'این تطبیق مالی نهایی است و امکان تلاش مجدد با همین بودجه را می‌بندد. ادامه می‌دهید؟':'This final reconciliation closes retry with the same reserved budget. Continue?' }}">{{ $isFa?'ثبت تطبیق نهایی':'Post final reconciliation' }}</button>
                </form>
            @else
                <div class="notice"><x-icon name="clock" /><p>{{ $isFa ? 'تطبیق مالی فعال برای این وضعیت وجود ندارد.' : 'No active reconciliation task exists for this state.' }}</p></div>
            @endif
        </section>
        @endif

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مشتری' : 'Customer' }}</h2></div></div>
            <div class="user-hero">
                <span class="avatar avatar-lg">@if(data_get($user,'id'))<img src="@avatarUrl($user)" alt="" decoding="async" loading="lazy" onerror="this.style.display='none'; this.parentElement.classList.add('avatar-fallback')"><span class="avatar-initial" aria-hidden="true" style="display:none">{{ mb_strtoupper(mb_substr((string)data_get($user,'display_name','U'),0,1)) }}</span>@else{{ mb_strtoupper(mb_substr((string)data_get($user,'display_name','U'),0,1)) }}@endif</span>
                <div class="user-hero-copy"><h1 style="font-size:19px">{{ data_get($user,'display_name','—') }}</h1><div class="muted ltr">{{ data_get($user,'telegram_username') ? '@'.ltrim(data_get($user,'telegram_username'),'@') : data_get($user,'telegram_user_id','—') }}</div><div class="cluster" style="margin-top:8px"><x-status-chip :value="data_get($user,'kyc_level','base')" /><x-status-chip :value="data_get($user,'account_status','active')" /></div></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مالی' : 'Financials' }}</h2></div><x-status-chip :value="data_get($order,'payment_status','unfunded')" /></div>
            <dl class="definition-list">
                <div class="definition-row"><dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'media_budget_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'کارمزد خدمات' : 'Service fee' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'service_fee_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'کارمزد درگاه' : 'Gateway fee' }}</dt><dd class="number">{{ number_format(intdiv((int)data_get($order,'gateway_fee_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</dd></div>
                <div class="definition-row"><dt><strong>{{ __('ui.common.total') }}</strong></dt><dd class="number"><strong>{{ number_format(intdiv((int)data_get($order,'total_irr',0),10)) }} {{ $isFa?'تومان':'Toman' }}</strong></dd></div>
                <div class="definition-row"><dt>GRAM</dt><dd class="number">{{ number_format((float)data_get($order,'gram_amount',0),3) }}</dd></div>
            </dl>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $targetLabel }}</h2>
                    <p class="card-subtitle">{{ $isFa ? 'کانال‌ها و ربات‌های انتخاب‌شده برای همین سفارش.' : 'Channels and bots selected for this order.' }}</p>
                </div>
                <span class="chip number">{{ $targets->count() }}</span>
            </div>
            @if($targets->isEmpty())
                <div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'هیچ هدفی برای این سفارش ثبت نشده است.' : 'No targets are recorded for this order.' }}</p></div>
            @else
                <div class="stack-sm">
                    @foreach($targets as $target)
                        @php($targetStatus = (string) data_get($target,'validation_status','pending'))
                        <div class="card card-soft" style="padding:12px">
                            <div class="cluster-between">
                                <div class="table-primary">
                                    <span class="avatar"><x-icon name="channel" /></span>
                                    <span class="table-primary-copy">
                                        <strong>{{ data_get($target,'channel_title') ?: data_get($target,'channel_username','—') }}</strong>
                                        <small class="ltr">{{ data_get($target,'channel_username') ? '@'.ltrim((string)data_get($target,'channel_username'),'@') : data_get($target,'public_url','—') }}@if((int)data_get($target,'members_snapshot',0) > 0) · {{ number_format((int)data_get($target,'members_snapshot',0)) }}@endif</small>
                                    </span>
                                </div>
                                <x-status-chip :value="$targetStatus" />
                            </div>

                            @if($status === 'support_review')
                                <div class="stack-sm" style="margin-top:10px">
                                    @if(!in_array($targetStatus, ['approved','eligible'], true))
                                        <form action="{{ $safeRoute('admin.orders.targets.decision',['order'=>$id,'target'=>data_get($target,'id')]) }}" method="post" data-loading-form>
                                            @csrf
                                            <input type="hidden" name="decision" value="approved">
                                            <button class="btn btn-sm btn-primary btn-block" type="submit"><x-icon name="check" />{{ $isFa ? 'تأیید هدف' : 'Approve target' }}</button>
                                        </form>
                                    @endif
                                    <form class="form-grid" action="{{ $safeRoute('admin.orders.targets.decision',['order'=>$id,'target'=>data_get($target,'id')]) }}" method="post" data-loading-form>
                                        @csrf
                                        <input type="hidden" name="decision" value="rejected">
                                        <input class="input" name="note" maxlength="1000" required placeholder="{{ $isFa ? 'دلیل رد این هدف' : 'Reason for rejecting this target' }}">
                                        <button class="btn btn-sm btn-danger btn-block" type="submit"><x-icon name="close" />{{ $isFa ? 'رد هدف' : 'Reject target' }}</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

    </aside>

    <div class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'محتوای تبلیغ' : 'Ad content' }}</h2><p class="card-subtitle">{{ $isFa ? 'نسخه فعلی سفارش' : 'Current campaign revision' }}</p></div><span class="chip number">{{ mb_strlen((string)data_get($revision,'ad_text','')) }}/160</span></div>
            <dl class="definition-list">
                <div class="definition-row"><dt>{{ $isFa ? 'نوع تبلیغ' : 'Ad placement' }}</dt><dd>{{ $placementLabel }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'لینک مقصد' : 'Destination' }}</dt><dd class="ltr" style="overflow-wrap:anywhere">{{ data_get($revision,'destination_url','—') }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'پلن' : 'Plan' }}</dt><dd>{{ data_get($revision,'plan','standard') }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'تکرار برای هر نفر' : 'Daily view limit per user' }}</dt><dd class="number">{{ max(1, (int) data_get($revision,'daily_view_limit_per_user', data_get($revision,'frequency_cap',1))) }} {{ $isFa ? 'بار در روز' : 'times/day' }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'هدف نمایش' : 'Impression goal' }}</dt><dd class="number">{{ number_format((int)data_get($revision,'impression_goal',0)) }}</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'پیشنهاد CPM' : 'CPM bid' }}</dt><dd class="number">{{ number_format((float)data_get($revision,'cpm_gram',0), 3) }} GRAM/1K</dd></div>
                <div class="definition-row"><dt>{{ $isFa ? 'زبان تبلیغ' : 'Ad language' }}</dt><dd>{{ data_get($revision,'language','fa') === 'fa' ? 'فارسی' : 'English' }}</dd></div>
                @php($searchKeywords = collect(data_get($revision,'search_keywords',[]))->filter()->values())
                @if($searchKeywords->isNotEmpty())<div class="definition-row"><dt>{{ $isFa ? 'کلیدواژه‌های جستجو' : 'Search keywords' }}</dt><dd><div class="cluster" style="gap:6px;flex-wrap:wrap">@foreach($searchKeywords as $kw)<span class="status-chip status-info">{{ $kw }}</span>@endforeach</div></dd></div>@endif
            </dl>

            @if(data_get($revision,'ad_media_path'))
                @php($mediaUrl = $safeRoute('admin.orders.ad-media', ['order' => $id]))
                <div class="stack-sm" style="margin-top:14px">
                    @if(data_get($revision,'ad_media_type') === 'video')
                        <video controls playsinline preload="metadata" style="max-width:100%;width:100%;max-height:420px;border-radius:10px;background:#000" src="{{ $mediaUrl }}"></video>
                    @else
                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener"><img src="{{ $mediaUrl }}" alt="{{ $isFa ? 'رسانه تبلیغ' : 'Ad media' }}" decoding="async" loading="lazy" style="max-width:100%;width:100%;max-height:420px;object-fit:contain;border-radius:10px;background:var(--ap-surface-muted,#f6f6f6)"></a>
                    @endif
                    <div class="cluster" style="gap:6px"><span class="status-chip status-info">{{ data_get($revision,'ad_media_type') === 'video' ? ($isFa ? 'ویدیو' : 'Video') : ($isFa ? 'تصویر' : 'Image') }}</span><a class="btn btn-sm btn-secondary" href="{{ $mediaUrl }}" target="_blank" rel="noopener">{{ $isFa ? 'باز کردن' : 'Open' }}</a></div>
                </div>
            @endif
            <div class="card card-soft" style="margin-top:14px;padding:14px"><p style="margin:0;white-space:pre-wrap;overflow-wrap:anywhere">{{ trim(str_replace("\u{2063}",'',(string)data_get($revision,'ad_text',''))) ?: '—' }}</p></div>
        </section>


        <section class="card">
            <div class="card-head"><div><h2 class="card-title">Telegram Ads</h2><p class="card-subtitle">{{ $isFa ? 'تأیید پشتیبانی و تصمیم Telegram دو مرحله جدا هستند.' : 'Support approval and Telegram decision are separate stages.' }}</p></div><x-status-chip :value="data_get($submission,'status','pending')" /></div>
            @if($status === 'queued_for_telegram')
                <form class="form-grid" action="{{ $safeRoute('admin.orders.telegram-submission',['order'=>$id]) }}" method="post" data-loading-form>@csrf
                    <div class="field-row"><div class="field"><label class="field-label required" for="external-ad-id">Telegram Ad ID</label><input class="input ltr" id="external-ad-id" name="external_ad_id" required maxlength="150" value="{{ old('external_ad_id') }}"></div><div class="field"><label class="field-label" for="telegram-account">{{ $isFa ? 'حساب تبلیغاتی' : 'Ads account' }}</label><input class="input" id="telegram-account" name="external_account_label" maxlength="150" value="{{ old('external_account_label') }}"></div></div>
                    <div class="field"><label class="field-label" for="telegram-submission-note">{{ $isFa?'یادداشت ثبت':'Submission note' }}</label><textarea class="textarea" id="telegram-submission-note" name="note" maxlength="2000">{{ old('note') }}</textarea></div>
                    <button class="btn btn-primary" type="submit"><x-icon name="send" />{{ $isFa?'ثبت شناسه و ارسال به بررسی Telegram':'Save ID and mark submitted' }}</button>
                </form>
            @elseif($status === 'telegram_review')
                <dl class="definition-list"><div class="definition-row"><dt>Telegram Ad ID</dt><dd class="number ltr">{{ data_get($submission,'external_ad_id','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'حساب تبلیغاتی':'Ads account' }}</dt><dd>{{ data_get($submission,'external_account_label','—') }}</dd></div></dl>
                <div class="two-column" style="margin-top:16px">
                    <form action="{{ $safeRoute('admin.orders.telegram-decision',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="decision" value="approved"><button class="btn btn-primary btn-block" type="submit"><x-icon name="check" />{{ $isFa?'Telegram تأیید کرد':'Telegram approved' }}</button></form>
                    <form class="form-grid" action="{{ $safeRoute('admin.orders.telegram-decision',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="decision" value="rejected"><div class="field"><label class="field-label required" for="telegram-rejection-reason">{{ $isFa?'دلیل رد Telegram':'Telegram rejection reason' }}</label><textarea class="textarea" id="telegram-rejection-reason" name="rejection_reason" required maxlength="2000">{{ old('rejection_reason') }}</textarea></div><button class="btn btn-danger btn-block" type="submit"><x-icon name="warning" />{{ $isFa?'Telegram رد کرد':'Record Telegram rejection' }}</button></form>
                </div>
            @elseif($submission)
                <dl class="definition-list">
                    <div class="definition-row"><dt>Telegram Ad ID</dt><dd class="number ltr">{{ data_get($submission,'external_ad_id','—') }}</dd></div>
                    <div class="definition-row"><dt>{{ $isFa?'حساب تبلیغاتی':'Ads account' }}</dt><dd>{{ data_get($submission,'external_account_label','—') }}</dd></div>
                    <div class="definition-row"><dt>{{ $isFa?'وضعیت Telegram':'Telegram status' }}</dt><dd><x-status-chip :value="data_get($submission,'status','pending')" /></dd></div>
                    @if(data_get($submission,'rejection_reason'))<div class="definition-row"><dt>{{ $isFa?'دلیل رد':'Rejection reason' }}</dt><dd>{{ data_get($submission,'rejection_reason') }}</dd></div>@endif
                </dl>
            @else
                <div class="notice"><x-icon name="clock" /><p>{{ $isFa?'پس از تأیید پشتیبانی و آماده‌شدن سفارش، عملیات Telegram اینجا فعال می‌شود.':'Telegram actions become available after support approval.' }}</p></div>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'ثبت آمار دستی' : 'Record metric snapshot' }}</h2><p class="card-subtitle">{{ $isFa ? 'فقط نمایش و هزینه تجمعی را از پنل Telegram Ads وارد کنید.' : 'Enter only cumulative impressions and spend from Telegram Ads.' }}</p></div></div>
            <form class="form-grid" action="{{ $safeRoute('admin.orders.metrics.store',['order'=>$id]) }}" method="post" data-loading-form>@csrf
                <div class="field-row"><div class="field"><label class="field-label required" for="as-of">{{ $isFa ? 'زمان آمار' : 'As of' }}</label><input class="input number" id="as-of" name="as_of_at" type="datetime-local" required value="{{ old('as_of_at', now()->format('Y-m-d\TH:i')) }}"></div><div class="field"><label class="field-label required" for="impressions">{{ $isFa ? 'نمایش' : 'Impressions' }}</label><input class="input number" id="impressions" name="impressions" type="number" min="{{ (int) data_get($latestMetric,'impressions',0) }}" value="{{ old('impressions', (int) data_get($latestMetric,'impressions',0)) }}" required></div></div>
                <div class="field-row"><div class="field"><label class="field-label required" for="spend-gram">Spend GRAM</label><input class="input number" id="spend-gram" name="spend_gram" type="number" min="0" step="0.001" value="{{ old('spend_gram', number_format((float) data_get($latestMetric,'spend_gram',0), 3, '.', '')) }}" required></div><div class="field"><label class="field-label" for="remaining-gram">Remaining GRAM</label><input class="input number" id="remaining-gram" name="remaining_budget_gram" type="number" min="0" step="0.001" value="{{ old('remaining_budget_gram') }}"></div></div>
                <button class="btn btn-primary" type="submit" data-confirm="{{ $isFa ? 'Snapshot ثبت شود؟' : 'Save snapshot?' }}"><x-icon name="chart" />{{ $isFa ? 'ثبت Snapshot' : 'Save snapshot' }}</button>
            </form>
        </section>
    </div>

    <aside class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اقدام بعدی' : 'Next action' }}</h2><p class="card-subtitle"><x-status-chip :value="$status" /></p></div></div>
            <div class="stack-sm">
                <form class="form-grid" action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>
                    @csrf
                    <div class="field">
                        <label class="field-label required" for="admin-manual-status">{{ $isFa ? 'تغییر وضعیت دستی' : 'Manual status change' }}</label>
                        <select class="select" id="admin-manual-status" name="to_status" required>
                            @foreach(\App\Enums\OrderStatus::cases() as $manualStatus)
                                <option value="{{ $manualStatus->value }}" @selected($manualStatus->value === $status)>
                                    {{ $manualStatus->label($isFa ? 'fa' : 'en') }}{{ $manualStatus->value === $status ? ($isFa ? ' — وضعیت فعلی' : ' — current') : '' }}
                                </option>
                            @endforeach
                        </select>
                        <input type="hidden" name="reason_code" value="manual_admin_override">
                    </div>
                    <button class="btn btn-secondary btn-block" type="submit" data-confirm="{{ $isFa ? 'وضعیت سفارش به مرحله انتخاب‌شده تغییر کند؟ این عملیات در تاریخچه ثبت می‌شود.' : 'Change the order to the selected status? This action will be recorded in history.' }}"><x-icon name="refresh" />{{ $isFa ? 'تغییر وضعیت' : 'Change status' }}</button>
                </form>

                @if($status === 'completed' && data_get($reconciliationTask,'status') === 'completed')
                    <div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'تسویه مالی قبلی نهایی شده و دست‌نخورده باقی می‌ماند؛ تغییر وضعیت دستی فقط چرخه وضعیت سفارش را اصلاح می‌کند.' : 'The finalized financial reconciliation remains unchanged; manual status change only corrects the order lifecycle state.' }}</p></div>
                @endif

                @if($status === 'support_review')
                    @if($targets->isEmpty() || $blockingTargets->isNotEmpty())
                        <div class="notice notice-warning"><x-icon name="warning" /><div><strong>{{ $isFa ? 'تأیید پشتیبانی هنوز قابل انجام نیست' : 'Support approval is not ready' }}</strong><p>{{ $isFa ? 'حداقل یک هدف لازم است و هدف‌های ردشده/غیرمجاز باید تعیین تکلیف شوند. تعداد موارد مسدودکننده: '.$blockingTargets->count() : 'At least one target is required and rejected/ineligible targets must be resolved. Blocking targets: '.$blockingTargets->count() }}</p></div></div>
                    @else
                        <form action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="queued_for_telegram"><button class="btn btn-primary btn-block" type="submit" data-confirm="{{ $isFa ? 'محتوا تأیید شود و همه هدف‌های Pending/Eligible نیز Approved شوند؟' : 'Approve the content and automatically approve pending/eligible targets?' }}"><x-icon name="check" />{{ $isFa ? 'تأیید پشتیبانی و آماده‌سازی Telegram' : 'Approve support review' }}</button></form>
                    @endif

                    <form class="form-grid" action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="changes_requested"><div class="field"><label class="field-label required" for="transition-change-note">{{ $isFa ? 'پیام اصلاح برای مشتری' : 'Correction message for customer' }}</label><textarea class="textarea" id="transition-change-note" name="note" required maxlength="2000" placeholder="{{ $isFa ? 'دقیقاً بنویسید چه چیزی باید اصلاح شود.' : 'Explain exactly what must be changed.' }}">{{ old('note') }}</textarea></div><button class="btn btn-danger btn-block" type="submit" data-confirm="{{ $isFa ? 'سفارش برای اصلاح به مشتری برگردانده شود؟' : 'Return this order to the customer for changes?' }}"><x-icon name="edit" />{{ $isFa ? 'رد پشتیبانی و درخواست اصلاح' : 'Request changes' }}</button></form>

                @elseif($status === 'telegram_rejected')
                    @if(data_get($reconciliationTask,'status') === 'completed')
                        <div class="notice notice-warning"><x-icon name="lock" /><p>{{ $isFa ? 'تطبیق مالی این رد Telegram نهایی شده است؛ تلاش مجدد با همان بودجه بسته شده است.' : 'Reconciliation is final; this paid order cannot be retried with the same held budget.' }}</p></div>
                    @else
                        <div class="notice notice-warning"><x-icon name="warning" /><p>{{ $isFa ? 'Telegram سفارش را بعد از تأیید پشتیبانی رد کرده است. اگر قابل اصلاح است، آن را به کاربر برگردانید؛ اگر تلاش مجدد ندارید، تطبیق مالی را نهایی کنید.' : 'Telegram rejected after support approval. Return it for correction if retryable; otherwise finalize reconciliation.' }}</p></div>
                        <form class="form-grid" action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="changes_requested"><div class="field"><label class="field-label required" for="telegram-retry-note">{{ $isFa ? 'پیام اصلاح پس از رد Telegram' : 'Correction message after Telegram rejection' }}</label><textarea class="textarea" id="telegram-retry-note" name="note" required maxlength="2000" placeholder="{{ $isFa ? 'دلیل رد Telegram و اصلاح موردنیاز را برای کاربر بنویسید.' : 'Explain Telegram rejection and required correction.' }}"></textarea></div><button class="btn btn-warning btn-block" type="submit" data-confirm="{{ $isFa ? 'تطبیق مالی باز لغو و سفارش برای اصلاح مجدد به کاربر برگردد؟' : 'Cancel the open reconciliation task and return this order for correction?' }}"><x-icon name="edit" />{{ $isFa ? 'برگرداندن برای اصلاح مجدد' : 'Return for correction' }}</button></form>
                    @endif

                @elseif(in_array($status,['queued_for_telegram','telegram_review'],true))
                    <div class="notice"><x-icon name="send" /><p>{{ $status === 'queued_for_telegram' ? ($isFa ? 'شناسه تبلیغ را در بخش Telegram Ads ثبت کنید.' : 'Record the Telegram Ad ID in the Telegram Ads section.') : ($isFa ? 'نتیجه واقعی Telegram را از بخش Telegram Ads با «تأیید» یا «رد» ثبت کنید.' : 'Record Telegram’s actual approval/rejection in the Telegram Ads section.') }}</p></div>

                @elseif(in_array($status,['telegram_approved','scheduled'],true))
                    <form action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="active"><button class="btn btn-primary btn-block" type="submit" data-confirm="{{ $isFa ? 'کمپین وارد وضعیت در حال اجرا شود؟' : 'Mark this campaign as running?' }}"><x-icon name="play" />{{ $isFa ? 'شروع اجرا' : 'Mark running' }}</button></form>

                @elseif($status === 'pause_requested')
                    <form action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="paused"><button class="btn btn-secondary btn-block" type="submit"><x-icon name="pause" />{{ $isFa ? 'تأیید توقف' : 'Confirm pause' }}</button></form>

                @elseif($status === 'resume_requested')
                    <form action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="active"><button class="btn btn-primary btn-block" type="submit"><x-icon name="play" />{{ $isFa ? 'تأیید ادامه' : 'Confirm resume' }}</button></form>

                @elseif($status === 'active')
                    <form action="{{ $safeRoute('admin.orders.transition',['order'=>$id]) }}" method="post" data-loading-form>@csrf<input type="hidden" name="to_status" value="completed"><button class="btn btn-tonal btn-block" type="submit" data-confirm="{{ $isFa ? 'کمپین پایان‌یافته علامت‌گذاری شود؟' : 'Mark this campaign as completed?' }}"><x-icon name="check" />{{ $isFa ? 'پایان کمپین' : 'Complete campaign' }}</button></form>

                @elseif($status === 'changes_requested')
                    <div class="notice"><x-icon name="clock" /><p>{{ $isFa ? 'منتظر ارسال نسخه اصلاح‌شده توسط کاربر هستیم.' : 'Waiting for the customer to submit a corrected revision.' }}</p></div>
                @elseif($status === 'completed')
                    <div class="notice"><x-icon name="check" /><p>{{ $isFa ? 'کمپین پایان‌یافته است. در صورت نیاز می‌توانید از منوی «تغییر وضعیت دستی» بالای همین کارت، آن را به هر مرحله دیگری منتقل کنید.' : 'The campaign is completed. Use the manual status menu above to move it to any other lifecycle stage if needed.' }}</p></div>
                @else
                    <div class="notice"><x-icon name="clock" /><p>{{ $isFa ? 'در این وضعیت، اقدام مستقیم دیگری تعریف نشده است.' : 'No direct lifecycle action is available in this state.' }}</p></div>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'تاریخچه' : 'History' }}</h2></div></div>
            @if($events->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<ul class="timeline">@foreach($events->take(20) as $event)<li class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ \Illuminate\Support\Facades\Lang::has('ui.status.'.data_get($event,'to_status')) ? __('ui.status.'.data_get($event,'to_status')) : data_get($event,'to_status') }}</strong>@if(data_get($event,'note'))<span>{{ data_get($event,'note') }}</span>@endif<small class="number">{{ $formatDate(data_get($event,'created_at')) }}</small></span></li>@endforeach</ul>@endif
        </section>
    </aside>
</div>
@endsection
