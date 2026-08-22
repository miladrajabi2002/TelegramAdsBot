@extends('layouts.app')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $campaign = $campaign ?? $order ?? null;
    $id = data_get($campaign, 'public_id', data_get($campaign, 'id'));
    $revision = data_get($campaign, 'currentRevision') ?: data_get($campaign, 'current_revision') ?: $currentRevision ?? null;
    $title = data_get($revision, 'internal_title') ?: ($isFa ? 'کمپین بدون عنوان' : 'Untitled campaign');
    $status = data_get($campaign, 'status', 'draft');
    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $placement = (string) data_get($revision, 'placement_type', 'channel_posts');
    $placementLabel = [
        'channel_posts' => $isFa ? 'کانال' : 'Channel',
        'bot_messages' => $isFa ? 'ربات' : 'Bot',
        'search_results' => $isFa ? 'جستجو' : 'Search',
    ][$placement] ?? $placement;
    $targetLabel = $placement === 'bot_messages'
        ? ($isFa ? 'ربات‌های هدف' : 'Target bots')
        : ($isFa ? 'کانال‌های هدف' : 'Target channels');
    $metricItems = collect($metrics ?? data_get($campaign, 'metrics', []))->sortBy('as_of_at')->values();
    $latest = $metricItems->last() ?: ($latestMetrics ?? null);
    $targetItems = collect($targets ?? data_get($revision, 'targets', []));
    $maxImpressions = max(1, (int) $metricItems->max('impressions'));
    $pointCount = max(1, $metricItems->count() - 1);
    $chartPoints = $metricItems->map(function ($metric, $index) use ($pointCount, $maxImpressions) {
        $x = 20 + (($index / $pointCount) * 560);
        $y = 180 - (((int) data_get($metric, 'impressions', 0) / $maxImpressions) * 145);
        return round($x, 1).','.round($y, 1);
    })->implode(' ');
    $journeyStages = [
        'support_review' => 1, 'changes_requested' => 1,
        'queued_for_telegram' => 2, 'telegram_review' => 2, 'telegram_rejected' => 2,
        'telegram_approved' => 3, 'scheduled' => 3,
        'active' => 4, 'pause_requested' => 4, 'paused' => 4, 'resume_requested' => 4,
        'completed' => 5,
    ];
    $stage = $journeyStages[$status] ?? 0;
    $formatDate = static function ($value, string $format = 'yyyy/MM/dd HH:mm') use ($isFa): string {
        if (!$value) return '—';
        try {
            $date = \Illuminate\Support\Carbon::parse($value);
            return $isFa
                ? \App\Support\PersianDate::format($date, $format)
                : $date->timezone('UTC')->format(str_replace(['yyyy','MM','dd','HH','mm'], ['Y','m','d','H','i'], $format));
        } catch (\Throwable) {
            return (string) $value;
        }
    };
    $canPause = in_array($status, ['active', 'scheduled'], true);
    $canResume = $status === 'paused';
    $walletToman = (int) ($walletBalanceToman ?? 0);
    $dailyFrequency = (int) data_get($revision, 'daily_view_limit_per_user', data_get($revision, 'frequency_cap', 0));
    $conversionMetricValue = $placement === 'bot_messages' ? (int) data_get($latest, 'bot_starts', 0) : (int) data_get($latest, 'joins', 0);
    $conversionMetricLabel = $placement === 'bot_messages' ? ($isFa ? 'شروع ربات' : 'Bot starts') : ($isFa ? 'عضویت' : 'Joins');
    $adTextRaw = (string) data_get($revision, 'ad_text', '');
    $adTextDisplay = trim(str_replace("\u{2063}", '', $adTextRaw));
    $mediaPath = trim((string) data_get($revision, 'ad_media_path', ''));
    $mediaUrl = $mediaPath !== '' ? $safeRoute('app.campaigns.ad-media', ['campaign' => $id]) : null;
@endphp
@section('title', $title . ' — ' . __('ui.brand'))

@push('head')
<style>
.campaign-metric-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}
.campaign-metric-grid .metric { min-width:0; }
.campaign-ad-media {
    width:100%;
    max-height:440px;
    object-fit:contain;
    border-radius:14px;
    background:var(--ap-surface-muted,#f5f7f9);
}
@media (max-width:420px) {
    .campaign-metric-grid { gap:9px; }
    .campaign-metric-grid .metric { padding:13px 11px; }
    .campaign-metric-grid .metric-value { font-size:20px; }
}
</style>
@endpush

<header class="page-header">
    <div>
        <div class="eyebrow number">#{{ $id ?: '—' }}</div>
        <div class="cluster"><h1 class="page-title">{{ $title }}</h1><x-status-chip :value="$status" /></div>
        <p class="page-lead">{{ $isFa ? 'جزئیات تبلیغ، هدف‌ها، آمار و مسیر اجرای کمپین.' : 'Ad details, targets, metrics and campaign journey.' }}</p>
    </div>
    <div class="page-header-actions cluster">
        @if($canPause && \Illuminate\Support\Facades\Route::has('app.campaigns.pause'))
            <form action="{{ route('app.campaigns.pause', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-secondary" type="submit"><x-icon name="pause" />{{ $isFa ? 'درخواست توقف' : 'Request pause' }}</button></form>
        @endif
        @if($canResume && \Illuminate\Support\Facades\Route::has('app.campaigns.resume'))
            <form action="{{ route('app.campaigns.resume', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-primary" type="submit"><x-icon name="play" />{{ $isFa ? 'درخواست ادامه' : 'Request resume' }}</button></form>
        @endif
    </div>
</header>

@if($status === 'telegram_rejected')
    <div class="notice notice-danger"><x-icon name="warning" /><div><strong>{{ $isFa ? 'این تبلیغ توسط Telegram رد شده است' : 'Telegram rejected this ad' }}</strong><p>{{ data_get($telegramSubmission ?? null, 'rejection_reason') ?: ($isFa ? 'جزئیات رد و نتیجه تطبیق مالی از پنل ادمین ثبت می‌شود. اگر امکان اصلاح وجود داشته باشد، سفارش برای اصلاح به شما برگردانده خواهد شد.' : 'The rejection and reconciliation are recorded by support. If correction is possible, the order will be returned to you for changes.') }}</p></div></div>
@elseif($status === 'changes_requested')
    <div class="notice notice-warning"><x-icon name="edit" /><div style="flex:1"><strong>{{ $isFa ? 'پشتیبانی درخواست اصلاح داده است' : 'Support requested changes' }}</strong><p>{{ data_get($latestEvent ?? null, 'note') ?: ($isFa ? 'موارد اعلام‌شده را اصلاح و دوباره ارسال کنید.' : 'Update the requested items and submit again.') }}</p></div>@if(\Illuminate\Support\Facades\Route::has('app.campaigns.edit'))<a class="btn btn-sm btn-secondary" href="{{ route('app.campaigns.edit', ['campaign' => $id]) }}">{{ __('ui.actions.edit') }}</a>@endif</div>
@endif

@if($status === 'awaiting_payment')
    @php
        $totalIrr = max(0, (int) data_get($campaign, 'total_irr', 0));
        $totalToman = intdiv($totalIrr, 10);
        $shortageToman = max(0, $totalToman - $walletToman);
        $insufficientWallet = $shortageToman > 0;
        $orderUsd = max(0, (float) data_get($campaign, 'usd_amount', 0));
        $shortageUsd = $totalToman > 0 ? ($orderUsd * ($shortageToman / $totalToman)) : 0;
        $rialTopUpToman = max(200000, $shortageToman);
        $cryptoTopUpUsd = max(5, ceil($shortageUsd * 100) / 100);
        $walletTopUpUrl = $safeRoute('app.wallet.index', [
            'amount_toman' => $rialTopUpToman,
            'amount_usd' => number_format($cryptoTopUpUsd, 2, '.', ''),
            'required_toman' => $shortageToman,
            'campaign' => $id,
        ]).'#add-funds';
    @endphp
    <section class="section card" aria-labelledby="campaign-payment-title">
        <div class="card-head"><div><div class="eyebrow">{{ $isFa ? 'مرحله پرداخت' : 'Payment step' }}</div><h2 class="card-title" id="campaign-payment-title">{{ $isFa ? 'پرداخت با کیف پول' : 'Pay with wallet' }}</h2><p class="card-subtitle">{{ $isFa ? 'پرداخت این سفارش فقط از موجودی کیف پول انجام می‌شود.' : 'This order can only be paid from your wallet balance.' }}</p></div><x-status-chip value="awaiting_payment" /></div>
        <div class="two-column" style="align-items:start">
            <div>
                <dl class="definition-list">
                    <div class="definition-row"><dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt><dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'media_budget_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0) / max(1, 1 + ((int) data_get($campaign, 'service_markup_bps', 1500) / 10000)), 2) }}@endif</dd></div>
                    <div class="definition-row"><dt>{{ $isFa ? 'کارمزد خدمات' : 'Service fee' }}</dt><dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'service_fee_irr', 0), 10)) }} تومان @else{{ number_format((int) data_get($campaign, 'service_markup_bps', 1500) / 100, 2) }}%@endif</dd></div>
                    <div class="definition-row"><dt><strong>{{ __('ui.common.total') }}</strong></dt><dd class="number"><strong>@if($isFa){{ number_format($totalToman) }} تومان @else ${{ number_format($orderUsd, 2) }}@endif</strong></dd></div>
                    <div class="definition-row"><dt>{{ $isFa ? 'موجودی قابل‌استفاده' : 'Available wallet' }}</dt><dd class="number">@if($isFa){{ number_format($walletToman) }} تومان @else ${{ number_format((float) ($walletBalanceUsd ?? 0), 2) }}@endif</dd></div>
                    @if($insufficientWallet)<div class="definition-row"><dt><strong>{{ $isFa ? 'کسری کیف پول' : 'Wallet shortfall' }}</strong></dt><dd class="number" style="color:var(--ap-danger)"><strong>{{ number_format($shortageToman) }} {{ $isFa ? 'تومان' : 'Toman' }}</strong></dd></div>@endif
                    <div class="definition-row"><dt>{{ $isFa ? 'اعتبار قیمت' : 'Quote expires' }}</dt><dd class="number">{{ $formatDate(data_get($campaign, 'quote_expires_at')) }}</dd></div>
                </dl>
                <form style="margin-top:12px" action="{{ $safeRoute('app.campaigns.quote.refresh', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-secondary btn-block" type="submit"><x-icon name="trend" />{{ $isFa ? 'به‌روزرسانی قیمت' : 'Refresh quote' }}</button></form>
            </div>
            <div class="stack-sm">
                @if($insufficientWallet)
                    <div class="notice notice-warning"><x-icon name="wallet" /><div><strong>{{ $isFa ? 'موجودی کیف پول کافی نیست' : 'Wallet balance is insufficient' }}</strong><p>{{ $isFa ? 'برای این سفارش حداقل '.number_format($shortageToman).' تومان دیگر نیاز دارید.' : 'You need at least '.number_format($shortageToman).' Toman more.' }}</p></div></div>
                    <a class="btn btn-primary btn-block" href="{{ $walletTopUpUrl }}"><x-icon name="plus" />{{ $isFa ? 'شارژ کیف پول' : 'Top up wallet' }}</a>
                @else
                    <form class="option-card" action="{{ $safeRoute('app.campaigns.pay.wallet', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<span class="quick-icon"><x-icon name="wallet" /></span><span class="option-card-copy"><strong>{{ $isFa ? 'پرداخت از کیف پول' : 'Pay with wallet' }}</strong><small class="number">{{ $isFa ? number_format($walletToman).' تومان موجودی' : '$'.number_format((float) ($walletBalanceUsd ?? 0), 2).' available' }}</small><small>{{ $isFa ? 'پس از پرداخت، مبلغ رزرو و سفارش وارد بررسی پشتیبانی می‌شود.' : 'After payment, funds are reserved and the order enters support review.' }}</small></span><button class="btn btn-sm btn-primary" type="submit" data-confirm="{{ $isFa ? 'مبلغ سفارش از موجودی کیف پول کسر و رزرو شود؟' : 'Reserve the order amount from your wallet?' }}">{{ $isFa ? 'پرداخت' : 'Pay' }}</button></form>
                @endif
            </div>
        </div>
    </section>
@endif

{{-- 1) Ad type / order configuration --}}
<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'نوع تبلیغ و جزئیات سفارش' : 'Ad type & order details' }}</h2><p class="card-subtitle number">{{ $formatDate(data_get($campaign, 'created_at')) }}</p></div><span class="status-chip status-info">{{ $placementLabel }}</span></div>
    <dl class="definition-list">
        <div class="definition-row"><dt>{{ $isFa ? 'نوع تبلیغ' : 'Ad type' }}</dt><dd><strong>{{ $placementLabel }}</strong></dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'مقصد' : 'Destination' }}</dt><dd class="ltr" style="overflow-wrap:anywhere">{{ data_get($revision, 'destination_url', '—') }}</dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'هدف نمایش' : 'Impression goal' }}</dt><dd class="number">{{ number_format((int) data_get($revision, 'impression_goal', 0)) }}</dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'تکرار برای هر نفر' : 'Frequency per user' }}</dt><dd class="number">{{ $dailyFrequency > 0 ? $dailyFrequency : 1 }} {{ $isFa ? 'بار در روز' : 'times/day' }}</dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'پلن' : 'Plan' }}</dt><dd>{{ data_get($revision, 'plan', 'standard') === 'competitive' ? ($isFa ? 'رقابتی' : 'Competitive') : ($isFa ? 'استاندارد' : 'Standard') }}</dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'شروع پیشنهادی' : 'Preferred start' }}</dt><dd class="number">{{ $formatDate(data_get($campaign, 'planned_start_at')) }}</dd></div>
        <div class="definition-row"><dt>{{ $isFa ? 'مبلغ کل' : 'Total' }}</dt><dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'total_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0), 2) }}@endif</dd></div>
    </dl>
</section>

{{-- 2) Existing media + ad copy together --}}
<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'محتوای تبلیغ' : 'Ad content' }}</h2><p class="card-subtitle">{{ $isFa ? 'رسانه و متن نسخه فعلی' : 'Media and copy from the current revision' }}</p></div></div>
    <div class="two-column" style="align-items:start">
        <div>
            @if($mediaUrl)
                @if(data_get($revision, 'ad_media_type') === 'video')
                    <video class="campaign-ad-media" src="{{ $mediaUrl }}" controls playsinline preload="metadata"></video>
                @else
                    <img class="campaign-ad-media" src="{{ $mediaUrl }}" alt="{{ $isFa ? 'تصویر تبلیغ' : 'Ad image' }}" loading="lazy" decoding="async">
                @endif
            @else
                <x-empty-state icon="image" :description="$isFa ? 'برای این تبلیغ تصویری ثبت نشده است.' : 'No media is attached to this ad.'" style="min-height:180px" />
            @endif
        </div>
        <div class="card card-soft" style="padding:16px">
            <div class="eyebrow">{{ $isFa ? 'متن تبلیغ' : 'Ad copy' }}</div>
            <p style="margin:8px 0 0;white-space:pre-wrap;overflow-wrap:anywhere">{{ $adTextDisplay !== '' ? $adTextDisplay : '—' }}</p>
        </div>
    </div>
</section>

{{-- 3) Targets --}}
<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $targetLabel }}</h2><p class="card-subtitle number">{{ $targetItems->count() }}</p></div></div>
    @if($targetItems->isEmpty())
        <p class="muted">{{ $isFa ? 'هدفی ثبت نشده است.' : 'No targets recorded.' }}</p>
    @else
        <div class="stack-sm">
            @foreach($targetItems as $target)
                <div class="cluster-between">
                    <div class="table-primary"><span class="avatar"><x-icon name="channel" /></span><span class="table-primary-copy"><strong>{{ data_get($target, 'channel_title') ?: data_get($target, 'channel_username', '—') }}</strong><small class="ltr">{{ '@'.ltrim((string) data_get($target, 'channel_username', ''), '@') }}</small></span></div>
                    <x-status-chip :value="data_get($target, 'validation_status', 'pending')" />
                </div>
            @endforeach
        </div>
    @endif
</section>

{{-- 4) Four headline metrics as a true 2x2 grid --}}
<section class="section">
    <div class="campaign-metric-grid">
        <div class="metric"><div class="metric-label"><x-icon name="eye" size="sm" />{{ $isFa ? 'نمایش‌ها' : 'Impressions' }}</div><div class="metric-value number">{{ number_format((int) data_get($latest, 'impressions', 0)) }}</div><div class="metric-delta">{{ $isFa ? 'آخرین Snapshot' : 'Latest snapshot' }}</div></div>
        <div class="metric"><div class="metric-label"><x-icon name="users" size="sm" />{{ $conversionMetricLabel }}</div><div class="metric-value number">{{ number_format($conversionMetricValue) }}</div><div class="metric-delta">{{ $isFa ? 'تجمعی' : 'Cumulative' }}</div></div>
        <div class="metric"><div class="metric-label"><x-icon name="wallet" size="sm" />{{ $isFa ? 'هزینه مصرف‌شده' : 'Spend' }}</div><div class="metric-value number">{{ number_format((float) data_get($latest, 'spend_gram', 0), 3) }}</div><div class="metric-delta">GRAM</div></div>
        <div class="metric"><div class="metric-label"><x-icon name="chart" size="sm" />CPM</div><div class="metric-value number">{{ number_format((float) data_get($revision, 'cpm_gram', 0), 3) }}</div><div class="metric-delta">GRAM / 1K</div></div>
    </div>
</section>

{{-- 5) Snapshot trend. Current integration is manual operator entry. --}}
<section class="section card chart-card" aria-labelledby="performance-chart-title">
    <div class="card-head"><div><h2 class="card-title" id="performance-chart-title">{{ $isFa ? 'روند نمایش بر اساس Snapshot' : 'Impression trend by snapshot' }}</h2><p class="card-subtitle">{{ $isFa ? 'فعلاً آمار تجمعی توسط اپراتور از پنل Telegram Ads در پنل ادمین ثبت می‌شود.' : 'For now, cumulative metrics are entered manually by the operator from Telegram Ads.' }}</p></div>@if($latest)<span class="chip"><x-icon name="clock" size="sm" />{{ $formatDate(data_get($latest, 'as_of_at')) }}</span>@endif</div>
    @if($metricItems->isNotEmpty())
        @if($metricItems->count() >= 2)
            <svg class="svg-chart" viewBox="0 0 600 210" role="img" aria-labelledby="performance-chart-title performance-chart-desc"><desc id="performance-chart-desc">{{ $isFa ? 'نمودار روند تجمعی نمایش کمپین' : 'Cumulative campaign impression trend' }}</desc><line class="chart-gridline" x1="20" y1="35" x2="580" y2="35"/><line class="chart-gridline" x1="20" y1="107" x2="580" y2="107"/><line class="chart-gridline" x1="20" y1="180" x2="580" y2="180"/><polyline class="chart-line" points="{{ $chartPoints }}"/>@foreach($chartPoints ? explode(' ', $chartPoints) : [] as $point)@php([$x,$y]=explode(',',$point))<circle class="chart-point" cx="{{ $x }}" cy="{{ $y }}" r="4"/>@endforeach</svg>
        @endif
        <div class="table-wrap"><table class="data-table"><thead><tr><th>{{ __('ui.common.date') }}</th><th>{{ $isFa ? 'نمایش' : 'Impressions' }}</th><th>{{ $isFa ? 'عضویت' : 'Joins' }}</th><th>{{ $isFa ? 'شروع ربات' : 'Bot starts' }}</th><th>{{ $isFa ? 'هزینه' : 'Spend' }}</th></tr></thead><tbody>@foreach($metricItems as $metric)<tr><td class="number">{{ $formatDate(data_get($metric, 'as_of_at')) }}</td><td class="number">{{ number_format((int) data_get($metric, 'impressions', 0)) }}</td><td class="number">{{ number_format((int) data_get($metric, 'joins', 0)) }}</td><td class="number">{{ number_format((int) data_get($metric, 'bot_starts', 0)) }}</td><td class="number">{{ number_format((float) data_get($metric, 'spend_gram', 0), 3) }} GRAM</td></tr>@endforeach</tbody></table></div>
    @else
        <x-empty-state icon="chart" :description="$isFa ? 'هنوز Snapshot آماری ثبت نشده است. پس از شروع اجرا، اپراتور آمار Telegram Ads را در پنل ادمین ثبت می‌کند.' : 'No metric snapshot has been recorded yet.'" />
    @endif
</section>

{{-- Keep one lifecycle representation: Campaign journey. Separate status history removed. --}}
<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مسیر کمپین' : 'Campaign journey' }}</h2><p class="card-subtitle">{{ $isFa ? 'نمای خلاصه وضعیت اجرای سفارش' : 'A concise view of the campaign lifecycle' }}</p></div></div>
    <ol class="journey">
        @foreach([
            1 => [$isFa ? 'بررسی پشتیبانی' : 'Support review', $isFa ? 'کنترل محتوا و هدف‌ها' : 'Content and target checks'],
            2 => [$isFa ? 'بررسی Telegram' : 'Telegram review', $isFa ? 'ارسال و دریافت تصمیم Telegram' : 'Submission and Telegram decision'],
            3 => [$isFa ? 'تأیید و زمان‌بندی' : 'Approved and scheduled', $isFa ? 'آماده اجرا' : 'Ready for delivery'],
            4 => [$isFa ? 'در حال اجرا' : 'Running', $isFa ? 'نمایش و ثبت آمار' : 'Delivery and metrics'],
            5 => [$isFa ? 'پایان کمپین' : 'Campaign complete', $isFa ? 'گزارش و تسویه نهایی' : 'Final reporting and settlement'],
        ] as $index => [$label, $copy])
            <li class="journey-item {{ $stage > $index ? 'is-done' : ($stage === $index ? 'is-current' : '') }}"><span class="journey-node">@if($stage > $index)<x-icon name="check" size="sm" />@else<span class="number" style="font-size:10px">{{ $index }}</span>@endif</span><span class="journey-copy"><strong>{{ $label }}</strong><small>{{ $copy }}</small></span></li>
        @endforeach
    </ol>
</section>

@include('partials.payment_result_popup')
@endsection
