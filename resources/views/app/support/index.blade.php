@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'پشتیبانی' : 'Support') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $source = $tickets ?? [];
    $ticketItems = collect(is_object($source) && method_exists($source, 'items') ? $source->items() : $source);
    $selected = $activeTicket ?? $ticket ?? null;
    $messages = collect(data_get($selected, 'messages', $ticketMessages ?? []))->sortBy('created_at');
    $orders = collect($campaigns ?? $orders ?? []);
    $formatDate = static function ($value) use ($isFa): string { if (!$value) return '—'; try { $date = \Illuminate\Support\Carbon::parse($value); return $isFa ? \App\Support\PersianDate::format($date) : $date->timezone('UTC')->format('Y/m/d H:i'); } catch (\Throwable) { return (string) $value; } };
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'گفت‌وگو با تیم ما' : 'Talk to our team' }}</div><h1 class="page-title">{{ $isFa ? 'پشتیبانی' : 'Support' }}</h1><p class="page-lead">{{ $isFa ? 'برای هر موضوع یک تیکت بسازید و پاسخ‌ها را از همین صفحه دنبال کنید.' : 'Create one ticket per issue and follow every reply here.' }}</p></div></header>

<div class="two-column" style="align-items:start">
    <section class="stack-sm">
        <div class="section-heading"><h2>{{ $isFa ? 'تیکت‌های من' : 'My tickets' }}</h2></div>
        @if($ticketItems->isEmpty())
            <x-empty-state icon="support" :description="$isFa ? 'هنوز تیکتی ثبت نکرده‌اید.' : 'You have not created a ticket yet.'" />
        @else
            @foreach($ticketItems as $item)
                @php($itemId = data_get($item, 'public_id', data_get($item, 'id')))
                <a class="campaign-card" href="{{ $safeRoute('app.support.show', ['ticket' => $itemId]) }}">
                    <div class="campaign-card-title"><div class="cluster"><strong>{{ data_get($item, 'subject', $isFa ? 'تیکت پشتیبانی' : 'Support ticket') }}</strong><x-status-chip :value="data_get($item, 'status', 'open')" /></div><small class="number">#{{ $itemId }} · {{ $formatDate(data_get($item, 'last_message_at', data_get($item, 'created_at'))) }}</small></div><x-icon name="chevron" />
                </a>
            @endforeach
            @if(is_object($source) && method_exists($source, 'links'))<div class="pagination">{{ $source->links() }}</div>@endif
        @endif
    </section>

    <section class="card">
        @if($selected)
            @php($selectedId = data_get($selected, 'public_id', data_get($selected, 'id')))
            <div class="card-head"><div><h2 class="card-title">{{ data_get($selected, 'subject', $isFa ? 'تیکت پشتیبانی' : 'Support ticket') }}</h2><p class="card-subtitle number">#{{ $selectedId }}</p></div><x-status-chip :value="data_get($selected, 'status', 'open')" /></div>
            <div class="stack-sm" style="max-height:420px;overflow:auto;padding:2px" data-ticket-thread>
                @forelse($messages as $message)
                    @php($isMine = data_get($message, 'sender_type') === 'App\\Models\\User' || data_get($message, 'is_user', false))
                    <article class="card {{ $isMine ? 'card-soft' : '' }}" style="padding:12px;margin-inline-{{ $isMine ? 'end' : 'start' }}:28px"><strong style="font-size:12px">{{ $isMine ? ($isFa ? 'شما' : 'You') : ($isFa ? 'پشتیبانی' : 'Support') }}</strong><p style="margin:5px 0;white-space:pre-wrap">{{ data_get($message, 'body', '—') }}</p><small class="muted number">{{ $formatDate(data_get($message, 'created_at')) }}</small></article>
                @empty
                    <p class="muted">{{ $isFa ? 'هنوز پیامی در این تیکت نیست.' : 'There are no messages in this ticket yet.' }}</p>
                @endforelse
            </div>
            @if(data_get($selected, 'status', 'open') !== 'closed')
                <form class="form-grid" style="margin-top:16px" action="{{ $safeRoute('app.support.reply', ['ticket' => $selectedId]) }}" method="post" data-loading-form data-telegram-auth>@csrf<div class="field"><label class="field-label required" for="ticket-reply">{{ $isFa ? 'پاسخ شما' : 'Your reply' }}</label><textarea class="textarea" id="ticket-reply" name="body" required maxlength="2000" placeholder="{{ $isFa ? 'پیام را روشن و کامل بنویسید.' : 'Describe the issue clearly.' }}">{{ old('body') }}</textarea></div><button class="btn btn-primary" type="submit"><x-icon name="send" />{{ $isFa ? 'ارسال پاسخ' : 'Send reply' }}</button></form>
            @endif
        @else
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'تیکت جدید' : 'New ticket' }}</h2><p class="card-subtitle">{{ $isFa ? 'در صورت ارتباط با کمپین، سفارش را هم انتخاب کنید.' : 'Select an order when the issue relates to a campaign.' }}</p></div><x-icon name="support" class="text-primary" /></div>
            <form class="form-grid" action="{{ $safeRoute('app.support.store') }}" method="post" data-loading-form data-telegram-auth>@csrf<div class="field"><label class="field-label required" for="ticket-subject">{{ $isFa ? 'موضوع' : 'Subject' }}</label><input class="input" id="ticket-subject" name="subject" required maxlength="150" value="{{ old('subject') }}"></div><div class="field"><label class="field-label" for="ticket-order">{{ $isFa ? 'سفارش مرتبط' : 'Related order' }}</label><select class="select" id="ticket-order" name="order_id"><option value="">{{ $isFa ? 'بدون سفارش' : 'No order' }}</option>@foreach($orders as $order)<option value="{{ data_get($order, 'id') }}" @selected((string) old('order_id') === (string) data_get($order, 'id'))>#{{ data_get($order, 'public_id', data_get($order, 'id')) }} — {{ data_get($order, 'currentRevision.internal_title', data_get($order, 'current_revision.internal_title', $isFa ? 'کمپین' : 'Campaign')) }}</option>@endforeach</select></div><div class="field"><label class="field-label required" for="ticket-message">{{ $isFa ? 'پیام' : 'Message' }}</label><textarea class="textarea" id="ticket-message" name="body" required maxlength="2000">{{ old('body') }}</textarea></div><button class="btn btn-primary btn-block" type="submit"><x-icon name="send" />{{ $isFa ? 'ثبت تیکت' : 'Create ticket' }}</button></form>
        @endif
    </section>
</div>
@endsection
