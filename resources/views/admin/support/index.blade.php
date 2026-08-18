@extends('layouts.admin')

@section('title', __('ui.admin_nav.support'))
@section('page-title', __('ui.admin_nav.support'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $tickets ?? collect();
    $source = is_array($source) ? collect($source) : $source;
    $items = collect(is_object($source) && method_exists($source,'items') ? $source->items() : $source);
    $selected = $activeTicket ?? $ticket ?? null;
    $messages = collect(data_get($selected,'messages',$ticketMessages ?? []))->sortBy('created_at');
    $admins = collect($availableAdmins ?? []);
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \Illuminate\Support\Carbon::parse($value)->format('Y/m/d H:i'); } catch (\Throwable) { return (string)$value; } };
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa?'صف پاسخ‌گویی':'Support response queue' }}</div><h1 class="page-title">{{ __('ui.admin_nav.support') }}</h1><p class="page-lead">{{ $isFa?'تیکت‌ها را اولویت‌بندی، تخصیص و بدون خروج از صفحه پاسخ دهید.':'Prioritize, assign, and answer tickets without leaving the queue.' }}</p></div></header>
<div class="two-column" style="align-items:start;grid-template-columns:minmax(300px,.8fr) minmax(0,1.2fr)">
    <section class="stack">
        <form class="filters" method="get" action="{{ $safeRoute('admin.support.index') }}"><div class="field field-search"><label class="field-label" for="ticket-q">{{ __('ui.actions.search') }}</label><input class="input" id="ticket-q" name="q" value="{{ request('q') }}" placeholder="{{ $isFa?'موضوع، کاربر یا سفارش':'Subject, user, or order' }}"></div><div class="field"><label class="field-label" for="ticket-status">{{ __('ui.common.status') }}</label><select class="select" id="ticket-status" name="status"><option value="">{{ __('ui.common.all') }}</option><option value="open">Open</option><option value="pending_user">Pending user</option><option value="closed">Closed</option></select></div><button class="btn btn-secondary" type="submit"><x-icon name="filter" /></button></form>
        @if($items->isEmpty())<x-empty-state icon="support" :description="__('ui.empty.data')" />@else<div class="stack-sm">@foreach($items as $item)@php($ticketId=data_get($item,'public_id',data_get($item,'id')))<a class="campaign-card" href="{{ $safeRoute('admin.support.show',['ticket'=>$ticketId]) }}"><div class="campaign-card-title"><div class="cluster"><strong>{{ data_get($item,'subject','—') }}</strong><x-status-chip :value="data_get($item,'status','open')" /></div><small>{{ data_get($item,'user.display_name','—') }} · <span class="number">{{ $formatDate(data_get($item,'last_message_at',data_get($item,'created_at'))) }}</span></small></div><span class="status-chip {{ data_get($item,'priority')==='high'?'status-warning':'status-neutral' }}">{{ data_get($item,'priority','normal') }}</span></a>@endforeach</div>@if(method_exists($source,'links'))<div class="pagination">{{ $source->links() }}</div>@endif @endif
    </section>
    <section class="card">
        @if(!$selected)<x-empty-state icon="support" :description="$isFa?'برای مشاهده گفتگو یک تیکت را انتخاب کنید.':'Select a ticket to view the conversation.'" />@else
            @php($selectedId=data_get($selected,'public_id',data_get($selected,'id')))
            <div class="card-head"><div><h2 class="card-title">{{ data_get($selected,'subject','—') }}</h2><p class="card-subtitle">{{ data_get($selected,'user.display_name','—') }} · <span class="number">#{{ $selectedId }}</span></p></div><x-status-chip :value="data_get($selected,'status','open')" /></div>
            <div class="stack-sm" style="max-height:440px;overflow:auto">@forelse($messages as $message)@php($isAdmin=str_contains((string)data_get($message,'sender_type',''),'Admin')||data_get($message,'is_admin',false))<article class="card {{ $isAdmin?'card-soft':'' }}" style="padding:12px;margin-inline-{{ $isAdmin?'start':'end' }}:28px"><strong style="font-size:12px">{{ $isAdmin?data_get($message,'sender.name',$isFa?'پشتیبانی':'Support'):data_get($selected,'user.display_name',$isFa?'کاربر':'User') }}</strong><p style="margin:5px 0;white-space:pre-wrap">{{ data_get($message,'body','—') }}</p><small class="muted number">{{ $formatDate(data_get($message,'created_at')) }}</small></article>@empty<p class="muted">{{ __('ui.empty.data') }}</p>@endforelse</div>
            <form class="form-grid" style="margin-top:16px" action="{{ $safeRoute('admin.support.reply',['ticket'=>$selectedId]) }}" method="post" data-loading-form>@csrf<div class="field"><label class="field-label required" for="admin-reply">{{ $isFa?'پاسخ':'Reply' }}</label><textarea class="textarea" id="admin-reply" name="body" required maxlength="3000"></textarea></div><div class="field-row"><div class="field"><label class="field-label" for="assign-admin">{{ $isFa?'تخصیص به':'Assign to' }}</label><select class="select" id="assign-admin" name="assigned_admin_id"><option value="">{{ $isFa?'بدون تغییر':'No change' }}</option>@foreach($admins as $item)<option value="{{ data_get($item,'id') }}" @selected((string)data_get($selected,'assigned_admin_id')===(string)data_get($item,'id'))>{{ data_get($item,'name','—') }}</option>@endforeach</select></div><div class="field"><label class="field-label" for="next-ticket-status">{{ $isFa?'وضعیت پس از پاسخ':'Status after reply' }}</label><select class="select" id="next-ticket-status" name="status"><option value="pending_user">{{ $isFa?'منتظر پاسخ کاربر':'Pending user' }}</option><option value="open">{{ $isFa?'باز بماند':'Keep open' }}</option><option value="closed">{{ $isFa?'بسته شود':'Close' }}</option></select></div></div><button class="btn btn-primary" type="submit"><x-icon name="send" />{{ $isFa?'ارسال پاسخ':'Send reply' }}</button></form>
        @endif
    </section>
</div>
@endsection
