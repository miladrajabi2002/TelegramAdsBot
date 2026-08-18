@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'کمپین‌ها' : 'Campaigns') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $campaigns ?? $orders ?? [];
    $items = collect(is_object($source) && method_exists($source, 'items') ? $source->items() : $source);
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \Illuminate\Support\Carbon::parse($value)->format('Y/m/d'); } catch (\Throwable) { return (string) $value; } };
@endphp
<header class="page-header">
    <div><div class="eyebrow">{{ $isFa ? 'مدیریت تبلیغات' : 'Advertising workspace' }}</div><h1 class="page-title">{{ $isFa ? 'کمپین‌ها' : 'Campaigns' }}</h1><p class="page-lead">{{ $isFa ? 'وضعیت، هزینه و آخرین آمار همه سفارش‌های خود را یکجا ببینید.' : 'Review the status, spend, and latest metrics for every order.' }}</p></div>
    <div class="page-header-actions"><a class="btn btn-primary" href="{{ $safeRoute('app.campaigns.create') }}"><x-icon name="plus" />{{ __('ui.actions.new_campaign') }}</a></div>
</header>

<form class="filters" method="get" action="{{ $safeRoute('app.campaigns.index') }}">
    <div class="field field-search"><label class="field-label" for="campaign-search">{{ $isFa ? 'جست‌وجو' : 'Search' }}</label><input class="input" id="campaign-search" name="q" value="{{ request('q') }}" placeholder="{{ $isFa ? 'عنوان یا شناسه سفارش' : 'Title or order ID' }}"></div>
    <div class="field"><label class="field-label" for="campaign-status">{{ __('ui.common.status') }}</label><select class="select" id="campaign-status" name="status"><option value="">{{ __('ui.common.all') }}</option>@foreach(['draft','awaiting_payment','support_review','changes_requested','telegram_review','telegram_approved','active','paused','telegram_rejected','completed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __('ui.status.'.$status) }}</option>@endforeach</select></div>
    <button class="btn btn-secondary" type="submit"><x-icon name="filter" />{{ __('ui.actions.filter') }}</button>
</form>

@if($items->isEmpty())
    <x-empty-state icon="campaign" :description="__('ui.empty.campaigns')"><a class="btn btn-primary" href="{{ $safeRoute('app.campaigns.create') }}">{{ __('ui.actions.new_campaign') }}</a></x-empty-state>
@else
    <div class="stack-sm">
        @foreach($items as $campaign)
            @php
                $status = data_get($campaign, 'status', 'draft');
                $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
                $id = data_get($campaign, 'public_id', data_get($campaign, 'id'));
                $title = data_get($campaign, 'currentRevision.internal_title') ?: data_get($campaign, 'current_revision.internal_title') ?: ($isFa ? 'کمپین بدون عنوان' : 'Untitled campaign');
                $latestMetric = collect(data_get($campaign, 'metrics', []))->sortByDesc('as_of_at')->first();
                $impressions = (int) data_get($latestMetric, 'impressions', 0);
            @endphp
            <a class="campaign-card" href="{{ $safeRoute('app.campaigns.show', ['campaign' => $id]) }}">
                <div class="campaign-card-title">
                    <div class="cluster"><strong>{{ $title }}</strong><x-status-chip :value="$status" /></div>
                    <small class="number">#{{ $id ?: '—' }} · {{ $formatDate(data_get($campaign, 'created_at')) }}</small>
                    <div class="cluster" style="margin-top:9px"><span class="chip"><x-icon name="eye" size="sm" />{{ number_format($impressions) }} {{ $isFa ? 'نمایش' : 'views' }}</span><span class="chip"><x-icon name="calendar" size="sm" />{{ $formatDate(data_get($campaign, 'planned_start_at')) }}</span></div>
                </div>
                <div class="campaign-card-footer"><span><small class="muted">{{ $isFa ? 'مبلغ کل' : 'Total' }}</small><strong class="number">@if($isFa){{ number_format(intdiv((int) data_get($campaign, 'total_irr', 0), 10)) }} تومان @else ${{ number_format((float) data_get($campaign, 'usd_amount', 0), 2) }}@endif</strong></span><x-icon name="chevron" /></div>
            </a>
        @endforeach
    </div>
    @if(is_object($source) && method_exists($source, 'links'))<div class="pagination">{{ $source->links() }}</div>@endif
@endif
@endsection
