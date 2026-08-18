@extends('layouts.admin')

@section('title', app()->isLocale('fa') ? 'ویرایش کانال' : 'Edit channel')
@section('page-title', app()->isLocale('fa') ? 'ویرایش کانال پیشنهادی' : 'Edit suggested channel')

@section('content')
@php($isFa = app()->isLocale('fa'))
<header class="page-header">
    <div><div class="eyebrow ltr">{{ '@'.$channel->username }}</div><h1 class="page-title">{{ $channel->title }}</h1><p class="page-lead">{{ $isFa ? 'عضویت در دسته‌ها، وضعیت واجد شرایط بودن و اطلاعات نمایشی را به‌روز کنید.' : 'Update category membership, eligibility, and customer-facing details.' }}</p></div>
    <div class="page-header-actions"><a class="btn btn-secondary" href="{{ route('admin.channels.index') }}">{{ $isFa ? 'بازگشت' : 'Back' }}</a></div>
</header>

<section class="card" style="max-width:820px">
    <form class="form-grid" action="{{ route('admin.channels.update', $channel) }}" method="post" data-loading-form>
        @csrf @method('PUT')
        <div class="field-row">
            <div class="field"><label class="field-label required" for="channel-title">{{ $isFa ? 'عنوان کانال' : 'Channel title' }}</label><input class="input" id="channel-title" name="title" required maxlength="150" value="{{ old('title', $channel->title) }}"></div>
            <div class="field"><label class="field-label required" for="channel-username">Username</label><input class="input ltr" id="channel-username" name="username" required pattern="[A-Za-z0-9_]{5,32}" value="{{ old('username', $channel->username) }}"></div>
        </div>
        <div class="field-row">
            <div class="field"><label class="field-label required" for="channel-members">{{ $isFa ? 'تعداد عضو' : 'Member count' }}</label><input class="input number" id="channel-members" name="members_count" type="number" min="0" required value="{{ old('members_count', $channel->members_count) }}"></div>
            <div class="field"><label class="field-label required" for="channel-language">{{ $isFa ? 'زبان' : 'Language' }}</label><select class="select" id="channel-language" name="language" required>@foreach(['fa'=>'فارسی','en'=>'English','ar'=>'العربية','other'=>'Other'] as $value=>$label)<option value="{{ $value }}" @selected(old('language', $channel->language)===$value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="field"><label class="field-label required" for="channel-categories">{{ $isFa ? 'دسته‌بندی‌ها' : 'Categories' }}</label><select class="select" id="channel-categories" name="category_ids[]" multiple required style="min-height:150px">@foreach($categories as $category)<option value="{{ $category->id }}" @selected(in_array($category->id, old('category_ids', $channel->categories->pluck('id')->all())))>{{ $isFa ? $category->title_fa : $category->title_en }} ({{ $category->channels_count }}/30)</option>@endforeach</select></div>
        <div class="field"><label class="field-label" for="channel-note">{{ $isFa ? 'یادداشت داخلی' : 'Internal note' }}</label><textarea class="textarea" id="channel-note" name="internal_note" maxlength="1000">{{ old('internal_note', $channel->internal_note) }}</textarea></div>
        <label class="checkbox"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $channel->is_featured))><span>{{ $isFa ? 'در فهرست ویژه نمایش داده شود' : 'Feature this channel' }}</span></label>
        <div class="cluster"><button class="btn btn-primary" type="submit"><x-icon name="check" />{{ $isFa ? 'ذخیره تغییرات' : 'Save changes' }}</button></div>
    </form>
    <form action="{{ route('admin.channels.toggle', $channel) }}" method="post" data-loading-form style="margin-top:14px">@csrf<button class="btn {{ $channel->is_active ? 'btn-danger' : 'btn-secondary' }}" type="submit">{{ $channel->is_active ? ($isFa ? 'غیرفعال‌کردن کانال' : 'Disable channel') : ($isFa ? 'فعال‌کردن کانال' : 'Enable channel') }}</button></form>
</section>
@endsection
