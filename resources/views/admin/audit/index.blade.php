@extends('layouts.admin')

@section('title', app()->isLocale('fa') ? 'گزارش فعالیت‌ها' : 'Audit log')
@section('page-title', app()->isLocale('fa') ? 'گزارش فعالیت‌ها' : 'Audit log')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $logs ?? collect();
    $source = is_array($source) ? collect($source) : $source;
    $items = collect(is_object($source) && method_exists($source,'items') ? $source->items() : $source);
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value), 'yyyy/MM/dd HH:mm:ss'); } catch (\Throwable) { return (string)$value; } };
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa?'ردپای تغییرات حساس':'Trace of sensitive changes' }}</div><h1 class="page-title">{{ $isFa?'گزارش فعالیت‌ها':'Audit log' }}</h1><p class="page-lead">{{ $isFa?'این گزارش فقط خواندنی است و عملیات مدیر، کاربر و سرویس را با شناسه همبستگی نگه می‌دارد.':'This read-only log records administrator, user, and service actions with correlation IDs.' }}</p></div></header>
<form class="filters" method="get" action="{{ $safeRoute('admin.audit.index') }}"><div class="field field-search"><label class="field-label" for="audit-q">{{ __('ui.actions.search') }}</label><input class="input" id="audit-q" name="q" value="{{ request('q') }}" placeholder="{{ $isFa?'عملیات، بازیگر، موضوع یا Correlation ID':'Action, actor, subject, or correlation ID' }}"></div><div class="field"><label class="field-label" for="audit-action">{{ $isFa?'نوع عملیات':'Action' }}</label><input class="input ltr" id="audit-action" name="action" value="{{ request('action') }}"></div><div class="field"><label class="field-label" for="audit-date">{{ __('ui.common.date') }}</label><input class="input number" id="audit-date" name="date" type="date" value="{{ request('date') }}"></div><button class="btn btn-secondary" type="submit"><x-icon name="filter" />{{ __('ui.actions.filter') }}</button></form>
@if($items->isEmpty())
    <x-empty-state icon="document" :description="__('ui.empty.data')" />
@else
    <div class="table-wrap"><table class="data-table"><thead><tr><th>{{ $isFa?'رویداد':'Event' }}</th><th>{{ $isFa?'بازیگر':'Actor' }}</th><th>{{ $isFa?'موضوع':'Subject' }}</th><th>{{ $isFa?'دلیل':'Reason' }}</th><th>Correlation ID</th><th>IP</th><th>{{ __('ui.common.date') }}</th></tr></thead><tbody>@foreach($items as $log)<tr><td data-label="{{ $isFa?'رویداد':'Event' }}"><div class="table-primary"><span class="quick-icon"><x-icon name="document" /></span><span class="table-primary-copy"><strong class="ltr">{{ data_get($log,'action','—') }}</strong><small class="number">#{{ data_get($log,'id','—') }}</small></span></div></td><td data-label="{{ $isFa?'بازیگر':'Actor' }}">{{ data_get($log,'actor.name',class_basename((string)data_get($log,'actor_type','System'))) }} <small class="muted number">{{ data_get($log,'actor_id') }}</small></td><td data-label="{{ $isFa?'موضوع':'Subject' }}">{{ class_basename((string)data_get($log,'subject_type','—')) }} <small class="number">{{ data_get($log,'subject_id') }}</small></td><td data-label="{{ $isFa?'دلیل':'Reason' }}">{{ data_get($log,'reason','—') }}</td><td data-label="Correlation ID" class="number ltr">{{ data_get($log,'correlation_id','—') }}</td><td data-label="IP" class="number ltr">{{ data_get($log,'ip_address','—') }}</td><td data-label="{{ __('ui.common.date') }}" class="number nowrap">{{ $formatDate(data_get($log,'created_at')) }}</td></tr>@endforeach</tbody></table></div>

    @if(is_object($source) && method_exists($source, 'hasPages') && $source->hasPages())
        @php
            $currentPage = (int) $source->currentPage();
            $lastPage = (int) $source->lastPage();
            $startPage = max(1, $currentPage - 2);
            $endPage = min($lastPage, $currentPage + 2);
        @endphp
        <nav class="pagination" aria-label="{{ $isFa ? 'صفحه‌بندی گزارش فعالیت‌ها' : 'Audit log pagination' }}">
            @if($source->onFirstPage())
                <span aria-disabled="true">‹</span>
            @else
                <a href="{{ $source->previousPageUrl() }}" rel="prev" aria-label="{{ $isFa ? 'صفحه قبل' : 'Previous page' }}">‹</a>
            @endif

            @if($startPage > 1)
                <a href="{{ $source->url(1) }}" class="number">1</a>
                @if($startPage > 2)<span aria-hidden="true">…</span>@endif
            @endif

            @for($page = $startPage; $page <= $endPage; $page++)
                @if($page === $currentPage)
                    <span class="is-current number" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $source->url($page) }}" class="number">{{ $page }}</a>
                @endif
            @endfor

            @if($endPage < $lastPage)
                @if($endPage < $lastPage - 1)<span aria-hidden="true">…</span>@endif
                <a href="{{ $source->url($lastPage) }}" class="number">{{ $lastPage }}</a>
            @endif

            @if($source->hasMorePages())
                <a href="{{ $source->nextPageUrl() }}" rel="next" aria-label="{{ $isFa ? 'صفحه بعد' : 'Next page' }}">›</a>
            @else
                <span aria-disabled="true">›</span>
            @endif
        </nav>
    @endif
@endif
@endsection
