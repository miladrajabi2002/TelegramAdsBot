@extends('layouts.admin')

@section('title', __('ui.admin_nav.broadcasts'))
@section('page-title', __('ui.admin_nav.broadcasts'))
@section('page-kicker', app()->isLocale('fa') ? 'ارسال صف‌بندی‌شده و قابل پیگیری' : 'Queued, observable delivery')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $broadcasts ?? collect();
    $items = collect(is_object($source) && method_exists($source, 'items') ? $source->items() : $source);
    $summary = $stats ?? [];
    $audiences = collect($audienceOptions ?? [
        'all' => $isFa ? 'همه کاربران فعال' : 'All active users',
        'rial_verified' => $isFa ? 'احرازشده ریالی' : 'Rial-verified users',
        'active_customers' => $isFa ? 'مشتریان دارای سفارش' : 'Customers with campaigns',
    ]);
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); } catch (\Throwable) { return (string) $value; } };
@endphp

<header class="page-header">
    <div><div class="eyebrow">{{ $isFa ? 'ارتباط با مشتریان' : 'Customer communication' }}</div><h1 class="page-title">{{ $isFa ? 'پیام همگانی' : 'Broadcast messages' }}</h1><p class="page-lead">{{ $isFa ? 'هر ارسال در صف پس‌زمینه پردازش می‌شود تا محدودیت زمانی هاست و Telegram ارسال را قطع نکند.' : 'Every broadcast is processed in background batches to respect hosting and Telegram limits.' }}</p></div>
</header>

<div class="metric-grid">
    <div class="metric"><div class="metric-label"><x-icon name="send" size="sm" />{{ $isFa?'ارسال امروز':'Sent today' }}</div><div class="metric-value number">{{ number_format((int)data_get($summary,'sent_today',0)) }}</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="clock" size="sm" />{{ $isFa?'در صف':'Queued' }}</div><div class="metric-value number">{{ number_format((int)data_get($summary,'queued',0)) }}</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="check" size="sm" />{{ $isFa?'نرخ موفقیت':'Success rate' }}</div><div class="metric-value number">{{ number_format((float)data_get($summary,'success_rate',0),1) }}%</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="warning" size="sm" />{{ $isFa?'خطاهای امروز':'Failed today' }}</div><div class="metric-value number">{{ number_format((int)data_get($summary,'failed_today',0)) }}</div></div>
</div>

<div class="dashboard-layout section">
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa?'ساخت پیام':'Compose message' }}</h2><p class="card-subtitle">{{ $isFa?'پیام ابتدا ثبت و سپس به صف ارسال می‌رود.':'The message is recorded first, then queued for delivery.' }}</p></div></div>
        <form class="form-grid" action="{{ $safeRoute('admin.broadcasts.store') }}" method="post" enctype="multipart/form-data" data-loading-form data-broadcast-form>@csrf
            <div class="field"><label class="field-label required" for="broadcast-title">{{ $isFa?'عنوان داخلی':'Internal title' }}</label><input class="input" id="broadcast-title" name="title" maxlength="150" required value="{{ old('title') }}" placeholder="{{ $isFa?'مثلاً اطلاع‌رسانی ویژگی جدید':'e.g. New feature announcement' }}"></div>
            <div class="field"><label class="field-label required" for="broadcast-audience">{{ $isFa?'مخاطبان':'Audience' }}</label><select class="select" id="broadcast-audience" name="audience" required>@foreach($audiences as $value=>$label)@php $audienceValue = is_int($value) ? (string)data_get($label,'value',$label) : (string)$value; @endphp<option value="{{ $audienceValue }}" @selected(old('audience','all')===$audienceValue)>{{ is_array($label) ? data_get($label,$isFa?'label_fa':'label_en',data_get($label,'label',$audienceValue)) : $label }}</option>@endforeach</select></div>
            <div class="field"><label class="field-label" for="broadcast-media">{{ $isFa?'رسانه (اختیاری)':'Media (optional)' }}</label><input class="input" id="broadcast-media" name="media" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" data-broadcast-media><p class="field-hint">{{ $isFa?'عکس تا ۱۰ مگابایت؛ ویدیو یا GIF تا ۵۰ مگابایت. فایل در فضای خصوصی نگه‌داری می‌شود.':'Photo up to 10 MB; video or GIF up to 50 MB. Files are kept in private storage.' }}</p>@error('media')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label class="field-label required" for="broadcast-message">{{ $isFa?'متن پیام':'Message' }}</label><textarea class="textarea" id="broadcast-message" name="message" maxlength="4096" required data-count-target="#broadcast-counter" data-broadcast-message placeholder="{{ $isFa?'پیام شفاف و کوتاه بنویسید.':'Write a concise, clear message.' }}">{{ old('message') }}</textarea><div class="counter number" id="broadcast-counter">0 / 4096</div><p class="field-hint" data-broadcast-caption-hint hidden>{{ $isFa?'با انتخاب رسانه، متن به‌عنوان کپشن و حداکثر ۱۰۲۴ نویسه ارسال می‌شود.':'With media selected, the message becomes a caption limited to 1024 characters.' }}</p>@error('message')<p class="field-error">{{ $message }}</p>@enderror</div>
            <label class="checkbox"><input type="checkbox" name="confirmed" value="1" required><span>{{ $isFa?'مخاطب و متن نهایی را بررسی کردم؛ پیام فوراً وارد صف می‌شود.':'I reviewed the audience and final copy; the message will be queued immediately.' }}</span></label>
            <button class="btn btn-primary" type="submit"><x-icon name="send" />{{ $isFa?'ثبت و افزودن به صف':'Create and enqueue' }}</button>
        </form>
    </section>

    <aside class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'الگوی ارسال ایمن':'Safe delivery model' }}</h2></div></div><div class="timeline"><div class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ $isFa?'ثبت در پایگاه داده':'Persisted first' }}</strong><span>{{ $isFa?'قبل از ارسال، متن و مخاطب قابل حسابرسی است.':'Copy and audience are auditable before delivery.' }}</span></span></div><div class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ $isFa?'ارسال در دسته‌های کوچک':'Small batches' }}</strong><span>{{ $isFa?'با Retry و Rate Limit هوشمند.':'With retry and adaptive rate limiting.' }}</span></span></div><div class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ $isFa?'آمار دقیق':'Exact outcomes' }}</strong><span>{{ $isFa?'موفق، خطادار و بلاک‌شده جدا ثبت می‌شوند.':'Delivered, failed, and blocked recipients are recorded separately.' }}</span></span></div></div></aside>
</div>

<section class="card section">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa?'تاریخچه ارسال':'Delivery history' }}</h2><p class="card-subtitle">{{ $isFa?'وضعیت صف و نتیجه هر پیام':'Queue and outcome for every message' }}</p></div></div>
    @if($items->isEmpty())
        <x-empty-state icon="send" :description="$isFa?'هنوز پیامی ارسال نشده است.':'No broadcast has been created yet.'" />
    @else
        <div class="table-wrap"><table class="data-table"><thead><tr><th>{{ $isFa?'پیام':'Message' }}</th><th>{{ $isFa?'مخاطب':'Audience' }}</th><th>{{ __('ui.common.status') }}</th><th>{{ $isFa?'پیشرفت':'Progress' }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>
        @foreach($items as $broadcast)@php $total=max(0,(int)data_get($broadcast,'recipient_count',data_get($broadcast,'total_count',0))); $sent=max(0,(int)data_get($broadcast,'sent_count',0)); $percent=$total?min(100,round($sent/$total*100)):0; $mediaType=data_get($broadcast,'media_type'); @endphp<tr><td data-label="{{ $isFa?'پیام':'Message' }}"><div class="table-primary-copy"><strong>{{ data_get($broadcast,'title','—') }}</strong><small>@if($mediaType){{ ['photo'=>$isFa?'عکس':'Photo','video'=>$isFa?'ویدیو':'Video','animation'=>$isFa?'گیف':'GIF'][$mediaType] ?? $mediaType }} · @endif{{ \Illuminate\Support\Str::limit((string)data_get($broadcast,'message'),72) }}</small></div></td><td data-label="{{ $isFa?'مخاطب':'Audience' }}">{{ data_get($broadcast,'audience_filters.audience',data_get($broadcast,'audience','all')) }}</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($broadcast,'status','queued')" /></td><td data-label="{{ $isFa?'پیشرفت':'Progress' }}"><div class="number" style="min-width:120px"><div class="progress"><span style="--progress:{{ $percent }}%"></span></div><small>{{ number_format($sent) }} / {{ number_format($total) }}</small></div></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($broadcast,'scheduled_at',data_get($broadcast,'created_at'))) }}</td></tr>@endforeach
        </tbody></table></div>
        @if(is_object($source) && method_exists($source,'links'))<div class="pagination">{{ $source->links() }}</div>@endif
    @endif
</section>

<script>
(() => {
    const form = document.querySelector('[data-broadcast-form]');
    const media = form?.querySelector('[data-broadcast-media]');
    const message = form?.querySelector('[data-broadcast-message]');
    const hint = form?.querySelector('[data-broadcast-caption-hint]');
    if (!media || !message) return;

    const syncLimit = (notifyCounter = false) => {
        const hasMedia = (media.files?.length || 0) > 0;
        message.maxLength = hasMedia ? 1024 : 4096;
        if (hint) hint.hidden = !hasMedia;
        message.setCustomValidity(message.value.length > message.maxLength
            ? (document.documentElement.lang === 'fa' ? 'متن از حد مجاز بیشتر است.' : 'The message is too long.')
            : '');
        if (notifyCounter) message.dispatchEvent(new Event('input', { bubbles: true }));
    };

    media.addEventListener('change', () => syncLimit(true));
    message.addEventListener('input', () => syncLimit(false));
    syncLimit();
})();
</script>
@endsection
