@extends('layouts.admin')

@section('title', __('ui.admin_nav.dashboard'))
@section('page-title', __('ui.admin_nav.dashboard'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $data = $stats ?? [];
    $period = request('period', '30d');
    $periodLabel = match ($period) {
        'today' => $isFa ? 'امروز' : 'Today',
        '7d' => $isFa ? '7 روز اخیر' : 'Last 7 days',
        'month' => $isFa ? 'ماه جاری شمسی' : 'Current Persian month',
        default => $isFa ? '30 روز اخیر' : 'Last 30 days',
    };
    $recent = collect($recentOrders ?? []);
    $queue = [
        ['label' => $isFa ? 'احراز هویت منتظر بررسی' : 'Identity reviews waiting', 'count' => (int) data_get($data, 'pending_kyc', $pendingKycCount ?? 0), 'icon' => 'identity', 'route' => 'admin.kyc.index'],
        ['label' => $isFa ? 'سفارش نیازمند اقدام' : 'Orders needing action', 'count' => (int) data_get($data, 'pending_orders', 0), 'icon' => 'campaign', 'route' => 'admin.orders.index'],
        ['label' => $isFa ? 'پرداخت معلق یا مغایرت' : 'Held or mismatched payments', 'count' => (int) data_get($data, 'held_payments', 0), 'icon' => 'transaction', 'route' => 'admin.transactions.index'],
        ['label' => $isFa ? 'تیکت بدون پاسخ' : 'Tickets awaiting reply', 'count' => (int) data_get($data, 'unanswered_tickets', 0), 'icon' => 'support', 'route' => 'admin.support.index'],
    ];
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); } catch (\Throwable) { return (string) $value; } };
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'نمای عملیاتی' : 'Operational view' }}</div><h1 class="page-title">{{ $isFa ? 'مرکز عملیات' : 'Operations center' }}</h1><p class="page-lead">{{ $isFa ? 'اول صف‌های نیازمند اقدام، سپس فروش و عملکرد را بررسی کنید.' : 'Triage action queues first, then review sales and performance.' }}</p></div><div class="page-header-actions"><form method="get" action="{{ $safeRoute('admin.dashboard') }}"><label class="sr-only" for="dashboard-period">{{ $isFa?'بازه گزارش':'Reporting period' }}</label><select class="select" id="dashboard-period" name="period" data-auto-submit><option value="today" @selected($period==='today')>{{ $isFa?'امروز':'Today' }}</option><option value="7d" @selected($period==='7d')>{{ $isFa?'7 روز':'7 days' }}</option><option value="month" @selected($period==='month')>{{ $isFa?'ماه شمسی':'Persian month' }}</option><option value="30d" @selected($period==='30d')>{{ $isFa?'30 روز':'30 days' }}</option></select></form><a class="btn btn-secondary" href="{{ $safeRoute('admin.reports.index') }}"><x-icon name="download" />{{ $isFa ? 'گزارش کامل' : 'Full report' }}</a></div></header>

<div class="metric-grid">
    <div class="metric"><div class="metric-label"><x-icon name="wallet" size="sm" />{{ $isFa ? 'فروش تأمین‌شده' : 'Funded sales' }}</div><div class="metric-value number">{{ number_format(intdiv((int) data_get($data, 'gross_sales_irr', 0), 10)) }} <small>{{ $isFa ? 'تومان' : 'Toman' }}</small></div><div class="metric-delta">{{ $periodLabel }}</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="trend" size="sm" />{{ $isFa ? 'کارمزد قراردادشده' : 'Committed service fees' }}</div><div class="metric-value number">{{ number_format(intdiv((int) data_get($data, 'service_revenue_irr', 0), 10)) }} <small>{{ $isFa ? 'تومان' : 'Toman' }}</small></div><div class="metric-delta">{{ number_format((float) data_get($data, 'service_margin_percent', 0), 1) }}% · {{ $periodLabel }}</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="campaign" size="sm" />{{ $isFa ? 'کمپین فعال' : 'Active campaigns' }}</div><div class="metric-value number">{{ number_format((int) data_get($data, 'active_campaigns', 0)) }}</div><div class="metric-delta">{{ $isFa ? 'در حال اجرا' : 'Currently delivering' }}</div></div>
    <div class="metric"><div class="metric-label"><x-icon name="users" size="sm" />{{ $isFa ? 'کاربر فعال' : 'Active users' }}</div><div class="metric-value number">{{ number_format((int) data_get($data, 'active_users', 0)) }}</div><div class="metric-delta">{{ $isFa ? '30 روز اخیر' : 'Last 30 days' }}</div></div>
</div>

<div class="dashboard-layout section">
    <div class="stack">
        <section class="card chart-card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'روند فروش تأمین‌شده' : 'Funded sales trend' }}</h2><p class="card-subtitle">{{ $periodLabel }} · {{ $isFa?'بر اساس زمان تأمین مالی سفارش':'Grouped by order funding date' }}</p></div><div class="chart-legend"><span class="legend-item"><i class="legend-swatch"></i>{{ $isFa ? 'فروش' : 'Sales' }}</span></div></div>
            @php($series = collect($revenueSeries ?? []))
            @if($series->isEmpty())
                <x-empty-state icon="chart" :description="__('ui.empty.data')" style="min-height:190px" />
            @else
                @php($max = max(1, (int) $series->max('gross_irr')))
                <div class="bar-list">@foreach($series->take(-8) as $point)<div class="bar-row"><div class="bar-row-meta"><span class="number">{{ data_get($point, 'label', data_get($point, 'date', '—')) }}</span><strong class="number">{{ number_format(intdiv((int) data_get($point, 'gross_irr', 0), 10)) }}</strong></div><div class="bar-track"><span class="bar-fill" style="--bar:{{ min(100, ((int) data_get($point, 'gross_irr', 0) / $max) * 100) }}%"></span></div></div>@endforeach</div>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'سفارش‌های اخیر' : 'Recent orders' }}</h2><p class="card-subtitle">{{ $isFa ? 'آخرین فعالیت ثبت‌شده' : 'Latest recorded activity' }}</p></div><a class="btn btn-text btn-sm" href="{{ $safeRoute('admin.orders.index') }}">{{ $isFa ? 'همه سفارش‌ها' : 'All orders' }}</a></div>
            @if($recent->isEmpty())<p class="muted">{{ __('ui.empty.data') }}</p>@else<div class="table-wrap"><table class="data-table"><thead><tr><th>{{ __('ui.common.order') }}</th><th>{{ __('ui.common.user') }}</th><th>{{ __('ui.common.amount') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($recent as $order)@php($id=data_get($order,'public_id',data_get($order,'id')))<tr><td data-label="{{ __('ui.common.order') }}"><a class="table-primary" href="{{ $safeRoute('admin.orders.show',['order'=>$id]) }}"><span class="quick-icon"><x-icon name="campaign" /></span><span class="table-primary-copy"><strong>{{ data_get($order,'currentRevision.internal_title',data_get($order,'current_revision.internal_title',$isFa?'کمپین':'Campaign')) }}</strong><small class="number">#{{ $id }}</small></span></a></td><td data-label="{{ __('ui.common.user') }}">{{ data_get($order,'user.display_name','—') }}</td><td data-label="{{ __('ui.common.amount') }}" class="number">{{ number_format(intdiv((int)data_get($order,'total_irr',0),10)) }}</td><td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($order,'status','draft')" /></td><td data-label="{{ __('ui.common.date') }}" class="number">{{ $formatDate(data_get($order,'created_at')) }}</td></tr>@endforeach</tbody></table></div>@endif
        </section>
    </div>

    <aside class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'نیازمند اقدام' : 'Needs attention' }}</h2><p class="card-subtitle">{{ $isFa ? 'صف‌ها بر اساس فوریت' : 'Queues ordered by urgency' }}</p></div></div>
        <div class="action-queue">@foreach($queue as $item)<a class="queue-item" href="{{ $safeRoute($item['route']) }}"><span class="queue-icon"><x-icon :name="$item['icon']" /></span><span class="queue-copy"><strong>{{ $item['label'] }}</strong><small>{{ $item['count'] ? ($isFa ? 'برای بررسی باز کنید' : 'Open to review') : ($isFa ? 'صف خالی است' : 'Queue is clear') }}</small></span><span class="status-chip {{ $item['count'] ? 'status-warning' : 'status-success' }} number">{{ $item['count'] }}</span></a>@endforeach</div>
    </aside>
</div>
@endsection
