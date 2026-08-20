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
$metricItems = collect($metrics ?? data_get($campaign, 'metrics', []))->sortBy('as_of_at')->values();
$latest = $metricItems->last() ?: ($latestMetrics ?? null);
$eventItems = collect($statusEvents ?? data_get($campaign, 'statusEvents', data_get($campaign, 'status_events', [])))->sortByDesc('created_at');
$targetItems = collect($targets ?? data_get($revision, 'targets', []));
$maxImpressions = max(1, (int) $metricItems->max('impressions'));
$pointCount = max(1, $metricItems->count() - 1);
$chartPoints = $metricItems->map(function ($metric, $index) use ($pointCount, $maxImpressions) { $x = 20 + (($index / $pointCount) * 560); $y = 180 - (((int) data_get($metric, 'impressions', 0) / $maxImpressions) * 145); return round($x, 1).','.round($y, 1); })->implode(' ');
$journeyStages = ['support_review' => 1, 'changes_requested' => 1, 'queued_for_telegram' => 2, 'telegram_review' => 2, 'telegram_approved' => 3, 'scheduled' => 3, 'active' => 4, 'pause_requested' => 4, 'paused' => 4, 'resume_requested' => 4, 'completed' => 5, 'telegram_rejected' => 2];
$stage = $journeyStages[$status] ?? 0;
$formatDate = static function ($value, string $format = 'yyyy/MM/dd HH:mm') use ($isFa): string { if (!$value) return '—'; try { $date = \Illuminate\Support\Carbon::parse($value); return $isFa ? \App\Support\PersianDate::format($date, $format) : $date->timezone('UTC')->format(str_replace(['yyyy','MM','dd','HH','mm'], ['Y','m','d','H','i'], $format)); } catch (\Throwable) { return (string) $value; } };
$canPause = in_array($status, ['active', 'scheduled'], true);
$canResume = $status === 'paused';
$walletToman = (int) ($walletBalanceToman ?? 0);
@endphp
@section('title', $title . ' — ' . __('ui.brand'))

<header class="page-header">
    <div>
        <div class="eyebrow number">#{{ $id ?: '—' }}</div>
        <div class="cluster">
            <h1 class="page-title">{{ $title }}</h1><x-status-chip :value="$status" />
        </div>
        <p class="page-lead">{{ $isFa ? 'آخرین وضعیت، هزینه و آمار ثبت‌شده این کمپین.' : 'The latest status, spend, and recorded metrics for this campaign.' }}</p>
    </div>
    <div class="page-header-actions cluster">
        @if($canPause && \Illuminate\Support\Facades\Route::has('app.campaigns.pause'))<form action="{{ route('app.campaigns.pause', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-secondary" type="submit"><x-icon name="pause" />{{ $isFa ? 'درخواست توقف' : 'Request pause' }}</button></form>@endif
        @if($canResume && \Illuminate\Support\Facades\Route::has('app.campaigns.resume'))<form action="{{ route('app.campaigns.resume', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-primary" type="submit"><x-icon name="play" />{{ $isFa ? 'درخواست ادامه' : 'Request resume' }}</button></form>@endif
    </div>
</header>

@if($status === 'telegram_rejected')
<div class="notice notice-danger"><x-icon name="warning" />
    <div><strong>{{ $isFa ? 'این تبلیغ توسط Telegram رد شده است' : 'Telegram rejected this ad' }}</strong>
        <p>{{ data_get($telegramSubmission ?? null, 'rejection_reason') ?: ($isFa ? 'بازپرداخت نقدی انجام نمی‌شود. پس از تطبیق اپراتور، فقط مبلغی که Telegram قطعی کسر نکرده باشد به اعتبار تبلیغاتیِ غیرقابل‌برداشت منتقل می‌شود.' : 'Cash refunds are not available. After operator reconciliation, only funds not finally deducted by Telegram become non-withdrawable advertising credit.') }}</p>
    </div>
</div>
@elseif($status === 'changes_requested')
<div class="notice notice-warning"><x-icon name="edit" />
    <div style="flex:1"><strong>{{ $isFa ? 'پشتیبانی درخواست اصلاح داده است' : 'Support requested changes' }}</strong>
        <p>{{ data_get($latestEvent ?? null, 'note') ?: ($isFa ? 'موارد اعلام‌شده را اصلاح و دوباره ارسال کنید.' : 'Update the requested items and submit again.') }}</p>
    </div>@if(\Illuminate\Support\Facades\Route::has('app.campaigns.edit'))<a class="btn btn-sm btn-secondary" href="{{ route('app.campaigns.edit', ['campaign' => $id]) }}">{{ __('ui.actions.edit') }}</a>@endif
</div>
@endif

@if($status === 'awaiting_payment')
@php
$totalIrr = max(0, (int) data_get($campaign, 'total_irr', 0));
$totalToman = intdiv($totalIrr, 10);
$shortageToman = max(0, $totalToman - $walletToman);
$insufficientWallet = $shortageToman > 0;
$orderUsd = max(0, (float) data_get($campaign, 'usd_amount', 0));
$shortageUsd = $totalToman > 0 ? ($orderUsd * ($shortageToman / $totalToman)) : 0;
// Respect current provider minimums so a pre-filled wallet top-up can
// be submitted immediately instead of failing validation.
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
    <div class="card-head">
        <div>
            <div class="eyebrow">{{ $isFa ? 'مرحله ۶ از ۶' : 'Step 6 of 6' }}</div>
            <h2 class="card-title" id="campaign-payment-title">{{ $isFa ? 'پرداخت با کیف پول' : 'Pay with wallet' }}</h2>
            <p class="card-subtitle">{{ $isFa ? 'پرداخت این سفارش فقط از موجودی کیف پول انجام می‌شود.' : 'This order can only be paid from your wallet balance.' }}</p>
        </div>
        <x-status-chip value="awaiting_payment" />
    </div>
    <div class="two-column" style="align-items:start">
        <div>
            <dl class="definition-list">
                <div class="definition-row">
                    <dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt>
                    <dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'media_budget_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0) / max(1, 1 + ((int) data_get($campaign, 'service_markup_bps', 1500) / 10000)), 2) }}@endif</dd>
                </div>
                <div class="definition-row">
                    <dt>{{ $isFa ? 'کارمزد خدمات' : 'Service fee' }}</dt>
                    <dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'service_fee_irr', 0), 10)) }} تومان @else{{ number_format((int) data_get($campaign, 'service_markup_bps', 1500) / 100, 2) }}%@endif</dd>
                </div>
                <div class="definition-row">
                    <dt><strong>{{ __('ui.common.total') }}</strong></dt>
                    <dd class="number"><strong>@if($isFa){{ number_format($totalToman) }} تومان @else ${{ number_format($orderUsd, 2) }}@endif</strong></dd>
                </div>
                <div class="definition-row">
                    <dt>{{ $isFa ? 'موجودی قابل‌استفاده' : 'Available wallet' }}</dt>
                    <dd class="number">@if($isFa){{ number_format($walletToman) }} تومان @else ${{ number_format((float) ($walletBalanceUsd ?? 0), 2) }}@endif</dd>
                </div>
                @if($insufficientWallet)<div class="definition-row">
                    <dt><strong>{{ $isFa ? 'کسری کیف پول' : 'Wallet shortfall' }}</strong></dt>
                    <dd class="number" style="color:var(--ap-danger)"><strong>{{ number_format($shortageToman) }} {{ $isFa ? 'تومان' : 'Toman' }}</strong></dd>
                </div>@endif
                <div class="definition-row">
                    <dt>{{ $isFa ? 'اعتبار قیمت' : 'Quote expires' }}</dt>
                    <dd class="number">{{ $formatDate(data_get($campaign, 'quote_expires_at')) }}</dd>
                </div>
            </dl>
            <form style="margin-top:12px" action="{{ $safeRoute('app.campaigns.quote.refresh', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>@csrf<button class="btn btn-secondary btn-block" type="submit"><x-icon name="trend" />{{ $isFa ? 'به‌روزرسانی قیمت' : 'Refresh quote' }}</button></form>
        </div>
        <div class="stack-sm">
            @if($insufficientWallet)
            <div class="notice notice-warning">
                <x-icon name="wallet" />
                <div>
                    <strong>{{ $isFa ? 'موجودی کیف پول کافی نیست' : 'Wallet balance is insufficient' }}</strong>
                    <p>{{ $isFa ? 'برای این سفارش حداقل '.number_format($shortageToman).' تومان دیگر نیاز دارید. کیف پول را شارژ کنید، سپس به همین کمپین برگردید و «پرداخت با کیف پول» را بزنید.' : 'You need at least '.number_format($shortageToman).' Toman more. Top up your wallet, return to this campaign, then pay with wallet.' }}</p>
                </div>
            </div>
            <a class="btn btn-primary btn-block" href="{{ $walletTopUpUrl }}"><x-icon name="plus" />{{ $isFa ? 'شارژ کیف پول' : 'Top up wallet' }}</a>
            <p class="field-help">{{ $isFa ? 'مبلغ لازم در صفحه شارژ به‌صورت خودکار داخل فیلد ریالی (در صورت فعال بودن پرداخت ریالی) و فیلد دلاری قرار می‌گیرد.' : 'The required amount will be pre-filled in both the rial field (when available) and the USD field.' }}</p>
            @else
            <form class="option-card" action="{{ $safeRoute('app.campaigns.pay.wallet', ['campaign' => $id]) }}" method="post" data-loading-form data-telegram-auth>
                @csrf
                <span class="quick-icon"><x-icon name="wallet" /></span>
                <span class="option-card-copy"><strong>{{ $isFa ? 'پرداخت از کیف پول' : 'Pay with wallet' }}</strong><small class="number">{{ $isFa ? number_format($walletToman).' تومان موجودی' : '$'.number_format((float) ($walletBalanceUsd ?? 0), 2).' available' }}</small><small>{{ $isFa ? 'پس از پرداخت، مبلغ رزرو و سفارش وارد بررسی پشتیبانی می‌شود.' : 'After payment, funds are reserved and the order enters support review.' }}</small></span>
                <button class="btn btn-sm btn-primary" type="submit" data-confirm="{{ $isFa ? 'مبلغ سفارش از موجودی کیف پول کسر و رزرو شود؟' : 'Reserve the order amount from your wallet?' }}">{{ $isFa ? 'پرداخت' : 'Pay' }}</button>
            </form>
            @endif
        </div>
    </div>
</section>
@endif

<div class="metric-grid section">
    <div class="metric">
        <div class="metric-label"><x-icon name="eye" size="sm" />{{ $isFa ? 'نمایش‌ها' : 'Impressions' }}</div>
        <div class="metric-value number">{{ number_format((int) data_get($latest, 'impressions', 0)) }}</div>
        <div class="metric-delta">{{ $isFa ? 'آخرین آمار ثبت‌شده' : 'Latest recorded snapshot' }}</div>
    </div>
    <div class="metric">
        <div class="metric-label"><x-icon name="users" size="sm" />{{ $isFa ? 'عضویت / شروع ربات' : 'Joins / bot starts' }}</div>
        <div class="metric-value number">{{ number_format((int) data_get($latest, 'joins', 0) + (int) data_get($latest, 'bot_starts', 0)) }}</div>
        <div class="metric-delta">{{ $isFa ? 'در صورت ارائه توسط منبع' : 'When available from the source' }}</div>
    </div>
    <div class="metric">
        <div class="metric-label"><x-icon name="wallet" size="sm" />{{ $isFa ? 'هزینه مصرف‌شده' : 'Spend' }}</div>
        <div class="metric-value number">{{ number_format((float) data_get($latest, 'spend_gram', 0), 3) }} GRAM</div>
        <div class="metric-delta">{{ $isFa ? 'هزینه رسانه' : 'Media spend' }}</div>
    </div>
    <div class="metric">
        <div class="metric-label"><x-icon name="chart" size="sm" />CPM</div>
        <div class="metric-value number">{{ number_format((float) data_get($revision, 'cpm_gram', 0), 3) }}</div>
        <div class="metric-delta">GRAM / 1K</div>
    </div>
</div>

<div class="two-column section" style="align-items:start">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'مسیر کمپین' : 'Campaign journey' }}</h2>
                <p class="card-subtitle">{{ $isFa ? 'هر مرحله پس از ثبت اپراتور به‌روزرسانی می‌شود.' : 'Each milestone updates after operator confirmation.' }}</p>
            </div>
        </div>
        <ol class="journey">
            @foreach([
            1 => [$isFa ? 'بررسی پشتیبانی' : 'Support review', $isFa ? 'کنترل محتوا و مقصد' : 'Copy and destination checks'],
            2 => [$isFa ? 'ثبت اولیه در Telegram' : 'Submitted to Telegram', $isFa ? 'در انتظار تصمیم Telegram' : 'Awaiting Telegram decision'],
            3 => [$isFa ? 'تأیید و زمان‌بندی' : 'Approved and scheduled', $isFa ? 'آماده ورود به مزایده' : 'Ready for delivery'],
            4 => [$isFa ? 'در حال اجرا' : 'Running', $isFa ? 'نمایش و ثبت آمار' : 'Delivery and metrics'],
            5 => [$isFa ? 'پایان کمپین' : 'Campaign complete', $isFa ? 'گزارش نهایی' : 'Final report'],
            ] as $index => [$label, $copy])
            <li class="journey-item {{ $stage > $index ? 'is-done' : ($stage === $index ? 'is-current' : '') }}"><span class="journey-node">@if($stage > $index)<x-icon name="check" size="sm" />@else<span class="number" style="font-size:10px">{{ $index }}</span>@endif</span><span class="journey-copy"><strong>{{ $label }}</strong><small>{{ $copy }}</small></span></li>
            @endforeach
        </ol>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'جزئیات سفارش' : 'Order details' }}</h2>
                <p class="card-subtitle number">{{ $formatDate(data_get($campaign, 'created_at')) }}</p>
            </div>
        </div>
        <dl class="definition-list">
            <div class="definition-row">
                <dt>{{ $isFa ? 'مقصد' : 'Destination' }}</dt>
                <dd class="ltr">{{ data_get($revision, 'destination_url', '—') }}</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'هدف نمایش' : 'Impression goal' }}</dt>
                <dd class="number">{{ number_format((int) data_get($revision, 'impression_goal', 0)) }}</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'تکرار برای هر نفر' : 'Frequency cap' }}</dt>
                <dd class="number">{{ data_get($revision, 'frequency_cap', '—') }}</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'پلن' : 'Plan' }}</dt>
                <dd>{{ data_get($revision, 'plan', 'standard') === 'competitive' ? ($isFa ? 'رقابتی' : 'Competitive') : ($isFa ? 'استاندارد' : 'Standard') }}</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'شروع پیشنهادی' : 'Preferred start' }}</dt>
                <dd class="number">{{ $formatDate(data_get($campaign, 'planned_start_at')) }}</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'مبلغ کل' : 'Total' }}</dt>
                <dd class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'total_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0), 2) }}@endif</dd>
            </div>
        </dl>
    </section>
</div>

<section class="section card chart-card" aria-labelledby="performance-chart-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="performance-chart-title">{{ $isFa ? 'روند نمایش' : 'Impression trend' }}</h2>
            <p class="card-subtitle">{{ $isFa ? 'بر اساس Snapshotهای ثبت‌شده توسط اپراتور' : 'Based on operator-recorded snapshots' }}</p>
        </div>@if($latest)<span class="chip"><x-icon name="clock" size="sm" />{{ $formatDate(data_get($latest, 'as_of_at')) }}</span>@endif
    </div>
    @if($metricItems->count() >= 2)
    <svg class="svg-chart" viewBox="0 0 600 210" role="img" aria-labelledby="performance-chart-title performance-chart-desc">
        <desc id="performance-chart-desc">{{ $isFa ? 'نمودار روند تجمعی نمایش کمپین' : 'Cumulative campaign impression trend' }}</desc>
        <line class="chart-gridline" x1="20" y1="35" x2="580" y2="35" />
        <line class="chart-gridline" x1="20" y1="107" x2="580" y2="107" />
        <line class="chart-gridline" x1="20" y1="180" x2="580" y2="180" />
        <polyline class="chart-line" points="{{ $chartPoints }}" />@foreach($chartPoints ? explode(' ', $chartPoints) : [] as $point)@php([$x,$y]=explode(',',$point))
        <circle class="chart-point" cx="{{ $x }}" cy="{{ $y }}" r="4" />@endforeach
    </svg>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('ui.common.date') }}</th>
                    <th>{{ $isFa ? 'نمایش' : 'Impressions' }}</th>
                    <th>{{ $isFa ? 'عضویت' : 'Joins' }}</th>
                    <th>{{ $isFa ? 'هزینه' : 'Spend' }}</th>
                    <th>{{ $isFa ? 'منبع' : 'Source' }}</th>
                </tr>
            </thead>
            <tbody>@foreach($metricItems as $metric)<tr>
                    <td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($metric, 'as_of_at')) }}</td>
                    <td data-label="{{ $isFa ? 'نمایش' : 'Impressions' }}" class="number">{{ number_format((int) data_get($metric, 'impressions', 0)) }}</td>
                    <td data-label="{{ $isFa ? 'عضویت' : 'Joins' }}" class="number">{{ number_format((int) data_get($metric, 'joins', 0)) }}</td>
                    <td data-label="{{ $isFa ? 'هزینه' : 'Spend' }}" class="number">{{ number_format((float) data_get($metric, 'spend_gram', 0), 3) }} GRAM</td>
                    <td data-label="{{ $isFa ? 'منبع' : 'Source' }}"><span class="chip">{{ data_get($metric, 'source', 'manual') === 'manual' ? ($isFa ? 'ثبت اپراتور' : 'Operator entry') : 'Telegram Ads' }}</span></td>
                </tr>@endforeach</tbody>
        </table>
    </div>
    @else
    <x-empty-state icon="chart" :description="$isFa ? 'هنوز آماری برای این کمپین ثبت نشده است. پس از شروع نمایش، گزارش اینجا قرار می‌گیرد.' : 'No metrics have been recorded yet. Reporting will appear here after delivery begins.'" />
    @endif
</section>

<div class="two-column section" style="align-items:start">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'متن تبلیغ' : 'Ad copy' }}</h2>
                <p class="card-subtitle number">{{ mb_strlen((string) data_get($revision, 'ad_text', '')) }}/160</p>
            </div>
        </div>
        <p style="white-space:pre-wrap">{{ data_get($revision, 'ad_text', '—') }}</p>
    </section>
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'کانال‌های هدف' : 'Target channels' }}</h2>
                <p class="card-subtitle number">{{ $targetItems->count() }}</p>
            </div>
        </div>@if($targetItems->isEmpty())<p class="muted">{{ $isFa ? 'کانالی ثبت نشده است.' : 'No channels recorded.' }}</p>@else<div class="stack-sm">@foreach($targetItems as $target)<div class="cluster-between">
                <div class="table-primary"><span class="avatar"><x-icon name="channel" /></span><span class="table-primary-copy"><strong>{{ data_get($target, 'channel_title') ?: data_get($target, 'channel_username', '—') }}</strong><small class="ltr">{{ '@'.ltrim((string) data_get($target, 'channel_username', ''), '@') }}</small></span></div><x-status-chip :value="data_get($target, 'validation_status', 'pending')" />
            </div>@endforeach</div>@endif
    </section>
</div>

<section class="section card">
    <div class="card-head">
        <div>
            <h2 class="card-title">{{ $isFa ? 'تاریخچه وضعیت' : 'Status history' }}</h2>
            <p class="card-subtitle">{{ $isFa ? 'همه تغییرات مهم سفارش' : 'Every important order change' }}</p>
        </div>
    </div>@if($eventItems->isEmpty())<p class="muted">{{ $isFa ? 'هنوز رویدادی ثبت نشده است.' : 'No status events yet.' }}</p>@else<ul class="timeline">@foreach($eventItems as $event)@php($eventStatus = data_get($event, 'to_status', 'pending'))<li class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ \Illuminate\Support\Facades\Lang::has('ui.status.'.$eventStatus) ? __('ui.status.'.$eventStatus) : str($eventStatus)->replace('_',' ')->title() }}</strong>@if(data_get($event, 'note'))<span>{{ data_get($event, 'note') }}</span>@endif<small class="number">{{ $formatDate(data_get($event, 'created_at')) }}</small></span></li>@endforeach</ul>@endif
</section>
@endsection

{{-- Payment result popup (success/error/warning) — shared partial.
    Renders only when a flash message is set, so safe to include on
    every page. NOWPayments redirects here after payment with
    ?payment=pending or ?payment=cancelled, and the controller sets
    flash messages that this popup surfaces to the user. --}}
@include('partials.payment_result_popup')
