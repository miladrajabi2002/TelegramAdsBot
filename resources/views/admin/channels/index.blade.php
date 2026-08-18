@extends('layouts.admin')

@section('title', __('ui.admin_nav.channels'))
@section('page-title', __('ui.admin_nav.channels'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $categoryItems = collect($categories ?? []);
    $source = $channels ?? collect();
    $source = is_array($source) ? collect($source) : $source;
    $channelItems = collect(is_object($source) && method_exists($source,'items') ? $source->items() : $source);
    $selectedCategory = $selectedCategory ?? $categoryItems->firstWhere('slug',request('category')) ?? null;
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \Illuminate\Support\Carbon::parse($value)->format('Y/m/d'); } catch (\Throwable) { return (string)$value; } };
@endphp
<style>
.cat-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:10px 8px; border-radius:10px; transition:background 160ms ease; }
.cat-row:hover { background: var(--ap-surface-soft); }
.cat-row .queue-copy { flex: 1 1 auto; min-width: 0; }
.cat-row-actions { display:flex; gap:4px; flex: 0 0 auto; }
.cat-row-actions .icon-btn { width:32px; height:32px; }
.cat-bilingual { display:flex; flex-direction:column; gap:2px; font-size:11px; }
.cat-bilingual small { color: var(--ap-muted); }
.cat-edit-form { display:grid; gap:8px; padding:10px 0 4px; border-block-start:1px dashed var(--ap-outline); margin-block-start:8px; }
.cat-edit-form[hidden] { display:none; }
</style>
<header class="page-header"><div><div class="eyebrow">{{ $isFa?'کاتالوگ پیشنهاد به مشتری':'Customer recommendation catalog' }}</div><h1 class="page-title">{{ __('ui.admin_nav.channels') }}</h1><p class="page-lead">{{ $isFa?'هر دسته حداکثر ۳۰ کانال فعال دارد؛ عنوان فارسی و انگلیسی برای هر دسته نمایش داده می‌شود و قابل ویرایش و حذف است.':'Each category may hold up to 30 active channels; both Persian and English titles are shown, editable, and deletable.' }}</p></div></header>

<div class="two-column" style="align-items:start;grid-template-columns:minmax(280px,.65fr) minmax(0,1.35fr)">
    <aside class="stack">
        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $isFa?'دسته‌بندی‌ها':'Categories' }}</h2>
                    <p class="card-subtitle number">{{ $categoryItems->count() }} {{ $isFa?'دسته':'total' }}</p>
                </div>
            </div>
            <div class="stack-sm">
            @forelse($categoryItems as $category)
                @php($count=(int)(data_get($category,'channels_count')??collect(data_get($category,'channels',[]))->count()))
                @php($catActive = (bool) data_get($category,'is_active',true))
                @php($editing = old('edit_category_id') === (string) data_get($category,'id'))
                <div class="cat-row" data-category-row="{{ data_get($category,'slug') }}">
                    <a class="queue-item" href="{{ $safeRoute('admin.channels.index',['category'=>data_get($category,'slug')]) }}" style="flex:1 1 auto">
                        <span class="queue-icon" style="background:var(--ap-primary-soft);color:var(--ap-primary)"><x-icon name="channel" /></span>
                        <span class="queue-copy">
                            <div class="cat-bilingual">
                                <strong>{{ data_get($category,'title_fa','—') }}</strong>
                                <small class="ltr">{{ data_get($category,'title_en','—') }}</small>
                            </div>
                            <small>{{ $catActive?($isFa?'فعال':'Active'):($isFa?'غیرفعال':'Inactive') }}</small>
                        </span>
                        <span class="status-chip {{ $count>=30?'status-warning':'status-neutral' }} number">{{ $count }}/30</span>
                    </a>
                    <div class="cat-row-actions">
                        <button type="button" class="icon-btn" data-category-edit="{{ data_get($category,'id') }}" aria-label="{{ $isFa?'ویرایش دسته':'Edit category' }}" title="{{ $isFa?'ویرایش':'Edit' }}">
                            <x-icon name="edit" />
                        </button>
                        <form method="post" action="{{ $safeRoute('admin.channels.categories.toggle',['category'=>data_get($category,'id')]) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="icon-btn" aria-label="{{ $catActive?($isFa?'غیرفعال':'Disable'):($isFa?'فعال':'Enable') }}" title="{{ $catActive?($isFa?'غیرفعال':'Disable'):($isFa?'فعال':'Enable') }}">
                                <x-icon name="{{ $catActive ? 'pause' : 'play' }}" />
                            </button>
                        </form>
                        <form method="post" action="{{ $safeRoute('admin.channels.categories.destroy',['category'=>data_get($category,'id')]) }}" style="display:inline" data-confirm="{{ $isFa?'این دسته حذف شود؟ کانال‌های آن جدا می‌شوند.':'Delete this category? Its channels will be detached.' }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="icon-btn" style="color:var(--ap-danger)" aria-label="{{ $isFa?'حذف دسته':'Delete category' }}" title="{{ $isFa?'حذف':'Delete' }}">
                                <x-icon name="trash" />
                            </button>
                        </form>
                    </div>
                </div>
                <form class="cat-edit-form" method="post" action="{{ $safeRoute('admin.channels.categories.update',['category'=>data_get($category,'id')]) }}" data-loading-form @if(!$editing) hidden @endif data-category-edit-form="{{ data_get($category,'id') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="edit_category_id" value="{{ data_get($category,'id') }}">
                    <div class="field-row">
                        <div class="field">
                            <label class="field-label required" for="cat-edit-fa-{{ data_get($category,'id') }}">{{ $isFa?'عنوان فارسی':'Persian title' }}</label>
                            <input class="input" id="cat-edit-fa-{{ data_get($category,'id') }}" name="title_fa" value="{{ old('title_fa', data_get($category,'title_fa')) }}" required>
                        </div>
                        <div class="field">
                            <label class="field-label required" for="cat-edit-en-{{ data_get($category,'id') }}">{{ $isFa?'عنوان انگلیسی':'English title' }}</label>
                            <input class="input ltr" id="cat-edit-en-{{ data_get($category,'id') }}" name="title_en" value="{{ old('title_en', data_get($category,'title_en')) }}" required>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="cat-edit-descfa-{{ data_get($category,'id') }}">{{ $isFa?'توضیحات فارسی':'Persian description' }}</label>
                        <textarea class="textarea" id="cat-edit-descfa-{{ data_get($category,'id') }}" name="description_fa" rows="2" maxlength="500">{{ old('description_fa', data_get($category,'description_fa')) }}</textarea>
                    </div>
                    <div class="field">
                        <label class="field-label" for="cat-edit-descen-{{ data_get($category,'id') }}">{{ $isFa?'توضیحات انگلیسی':'English description' }}</label>
                        <textarea class="textarea ltr" id="cat-edit-descen-{{ data_get($category,'id') }}" name="description_en" rows="2" maxlength="500">{{ old('description_en', data_get($category,'description_en')) }}</textarea>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label class="field-label" for="cat-edit-icon-{{ data_get($category,'id') }}">{{ $isFa?'نام آیکون':'Icon name' }}</label>
                            <input class="input ltr" id="cat-edit-icon-{{ data_get($category,'id') }}" name="icon" maxlength="40" value="{{ old('icon', data_get($category,'icon','folder')) }}">
                        </div>
                        <div class="field">
                            <label class="field-label" for="cat-edit-sort-{{ data_get($category,'id') }}">{{ $isFa?'ترتیب':'Sort order' }}</label>
                            <input class="input number" id="cat-edit-sort-{{ data_get($category,'id') }}" name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', data_get($category,'sort_order',0)) }}">
                        </div>
                        <div class="field">
                            <label class="checkbox" style="padding-top:30px">
                                <input type="checkbox" name="is_active" value="1" @if(old('is_active',(bool)data_get($category,'is_active',true))) checked @endif>
                                <span>{{ $isFa?'فعال':'Active' }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="cluster" style="gap:8px">
                        <button class="btn btn-sm btn-primary" type="submit"><x-icon name="save" />{{ $isFa?'ذخیره':'Save' }}</button>
                        <button class="btn btn-sm btn-secondary" type="button" data-category-edit-cancel="{{ data_get($category,'id') }}">{{ $isFa?'انصراف':'Cancel' }}</button>
                    </div>
                </form>
            @empty
                <p class="muted">{{ $isFa?'هنوز دسته‌ای ساخته نشده است.':'No categories yet.' }}</p>
            @endforelse
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa?'دسته جدید':'New category' }}</h2></div></div>
            <form class="form-grid" action="{{ $safeRoute('admin.channels.categories.store') }}" method="post" data-loading-form>
                @csrf
                <div class="field"><label class="field-label required" for="category-fa">عنوان فارسی</label><input class="input" id="category-fa" name="title_fa" required></div>
                <div class="field"><label class="field-label required" for="category-en">English title</label><input class="input ltr" id="category-en" name="title_en" required></div>
                <div class="field"><label class="field-label required" for="category-slug">Slug</label><input class="input ltr" id="category-slug" name="slug" pattern="[a-z0-9-]+" required></div>
                <div class="field"><label class="field-label" for="category-descfa">{{ $isFa?'توضیحات فارسی':'Persian description' }}</label><textarea class="textarea" id="category-descfa" name="description_fa" rows="2" maxlength="500"></textarea></div>
                <div class="field"><label class="field-label" for="category-descen">{{ $isFa?'توضیحات انگلیسی':'English description' }}</label><textarea class="textarea ltr" id="category-descen" name="description_en" rows="2" maxlength="500"></textarea></div>
                <div class="field-row">
                    <div class="field"><label class="field-label" for="category-icon">{{ $isFa?'آیکون':'Icon' }}</label><input class="input ltr" id="category-icon" name="icon" maxlength="40" placeholder="folder"></div>
                    <div class="field"><label class="field-label" for="category-sort">{{ $isFa?'ترتیب':'Sort order' }}</label><input class="input number" id="category-sort" name="sort_order" type="number" min="0" max="65535" value="0"></div>
                </div>
                <button class="btn btn-primary btn-block" type="submit"><x-icon name="plus" />{{ $isFa?'ساخت دسته':'Create category' }}</button>
            </form>
        </section>
    </aside>

    <div class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa?'افزودن کانال':'Add channel' }}</h2><p class="card-subtitle">{{ $isFa?'کانال عمومی و آخرین اطلاعات بررسی‌شده':'Public channel with latest verified details' }}</p></div><x-icon name="channel" class="text-primary" /></div>
            <form class="form-grid" action="{{ $safeRoute('admin.channels.store') }}" method="post" data-loading-form>@csrf<div class="field-row"><div class="field"><label class="field-label required" for="channel-title">{{ $isFa?'عنوان کانال':'Channel title' }}</label><input class="input" id="channel-title" name="title" required></div><div class="field"><label class="field-label required" for="channel-username">Username</label><input class="input ltr" id="channel-username" name="username" required placeholder="channel_name"></div></div><div class="field-row"><div class="field"><label class="field-label required" for="channel-url">Public URL</label><input class="input ltr" id="channel-url" name="public_url" type="url" required placeholder="https://t.me/channel"></div><div class="field"><label class="field-label" for="members-count">{{ $isFa?'تعداد عضو':'Member count' }}</label><input class="input number" id="members-count" name="members_count" type="number" min="0" value="0"></div></div><div class="field-row"><div class="field"><label class="field-label" for="channel-language">{{ $isFa?'زبان':'Language' }}</label><select class="select" id="channel-language" name="language"><option value="fa">فارسی</option><option value="en">English</option><option value="ar">العربية</option><option value="other">{{ $isFa?'سایر':'Other' }}</option></select></div><div class="field"><label class="field-label required" for="channel-category">{{ $isFa?'دسته‌بندی':'Category' }}</label><select class="select" id="channel-category" name="category_ids[]" multiple required style="min-height:96px">@foreach($categoryItems as $category)<option value="{{ data_get($category,'id') }}">{{ data_get($category,'title_fa') }} / {{ data_get($category,'title_en') }}</option>@endforeach</select></div></div><label class="checkbox"><input type="checkbox" name="is_featured" value="1"><span>{{ $isFa?'در فهرست ویژه نمایش داده شود':'Feature this channel' }}</span></label><button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ $isFa?'افزودن کانال':'Add channel' }}</button></form>
        </section>

        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa?'فهرست کانال‌ها':'Channel list' }}</h2><p class="card-subtitle">{{ $selectedCategory ? ($isFa?data_get($selectedCategory,'title_fa'):data_get($selectedCategory,'title_en')) : __('ui.common.all') }}</p></div></div>
            <form class="filters" method="get" action="{{ $safeRoute('admin.channels.index') }}"><div class="field field-search"><label class="field-label" for="channel-q">{{ __('ui.actions.search') }}</label><input class="input" id="channel-q" name="q" value="{{ request('q') }}" placeholder="{{ $isFa?'عنوان یا یوزرنیم':'Title or username' }}"></div><button class="btn btn-secondary" type="submit"><x-icon name="search" />{{ __('ui.actions.search') }}</button></form>
            @if($channelItems->isEmpty())
                <x-empty-state icon="channel" :description="__('ui.empty.data')" style="min-height:200px" />
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ $isFa?'کانال':'Channel' }}</th>
                                <th>{{ $isFa?'عضو':'Members' }}</th>
                                <th>{{ $isFa?'واجد شرایط':'Eligibility' }}</th>
                                <th>{{ $isFa?'آخرین بررسی':'Last checked' }}</th>
                                <th>{{ __('ui.common.status') }}</th>
                                <th>{{ __('ui.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($channelItems as $channel)
                            <tr>
                                <td data-label="{{ $isFa?'کانال':'Channel' }}">
                                    <div class="table-primary">
                                        <span class="avatar">
                                            @if(data_get($channel,'avatar_url'))<img src="{{ data_get($channel,'avatar_url') }}" alt="">@else{{ mb_strtoupper(mb_substr((string)data_get($channel,'title','C'),0,1)) }}@endif
                                        </span>
                                        <span class="table-primary-copy">
                                            <strong>{{ data_get($channel,'title','—') }}</strong>
                                            <small class="ltr">{{ '@'.ltrim((string)data_get($channel,'username',''),'@') }}</small>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="{{ $isFa?'عضو':'Members' }}" class="number">{{ number_format((int)data_get($channel,'members_count',0)) }}</td>
                                <td data-label="{{ $isFa?'واجد شرایط':'Eligibility' }}"><x-status-chip :value="data_get($channel,'eligibility_status','unverified')" /></td>
                                <td data-label="{{ $isFa?'آخرین بررسی':'Last checked' }}" class="number">{{ $formatDate(data_get($channel,'last_verified_at')) }}</td>
                                <td data-label="{{ __('ui.common.status') }}"><x-status-chip :value="data_get($channel,'is_active',true)?'active':'paused'" :label="data_get($channel,'is_active',true)?($isFa?'فعال':'Active'):($isFa?'غیرفعال':'Inactive')" /></td>
                                <td data-label="{{ __('ui.common.actions') }}">
                                    <div class="table-actions">
                                        <a class="btn btn-sm btn-secondary" href="{{ $safeRoute('admin.channels.edit',['channel'=>data_get($channel,'id')]) }}">{{ __('ui.actions.edit') }}</a>
                                        <form method="post" action="{{ $safeRoute('admin.channels.destroy',['channel'=>data_get($channel,'id')]) }}" style="display:inline" data-confirm="{{ $isFa?'این کانال حذف شود؟':'Delete this channel?' }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" aria-label="{{ $isFa?'حذف':'Delete' }}"><x-icon name="trash" /></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if(method_exists($source,'links'))<div class="pagination">{{ $source->links() }}</div>@endif
            @endif
        </section>
    </div>
</div>

<script>
// Category edit-form toggle — opens the form for a specific category.
document.querySelectorAll('[data-category-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.categoryEdit;
        document.querySelectorAll('[data-category-edit-form]').forEach((form) => {
            form.hidden = form.dataset.categoryEditForm !== id;
        });
        const target = document.querySelector(`[data-category-edit-form="${id}"]`);
        if (target && !target.hidden) {
            const firstInput = target.querySelector('input:not([type=hidden])');
            if (firstInput) firstInput.focus();
        }
    });
});
document.querySelectorAll('[data-category-edit-cancel]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.categoryEditCancel;
        const form = document.querySelector(`[data-category-edit-form="${id}"]`);
        if (form) form.hidden = true;
    });
});
</script>
@endsection
