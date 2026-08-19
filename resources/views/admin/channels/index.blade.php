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
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value), 'yyyy/MM/dd'); } catch (\Throwable) { return (string)$value; } };
    $lookupUrl = $safeRoute('admin.channels.lookup');
    $reorderUrl = $safeRoute('admin.channels.categories.reorder');
@endphp
<style>
.cat-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:10px 8px; border-radius:10px; transition:background 160ms ease, box-shadow 160ms ease; }
.cat-row:hover { background: var(--ap-surface-soft); }
.cat-row .queue-copy { flex: 1 1 auto; min-width: 0; }
.cat-row-actions { display:flex; gap:4px; flex: 0 0 auto; }
.cat-row-actions .icon-btn { width:32px; height:32px; }
.cat-edit-form { display:grid; gap:8px; padding:10px 0 4px; border-block-start:1px dashed var(--ap-outline); margin-block-start:8px; }
.cat-edit-form[hidden] { display:none; }

/* Drag handle */
.cat-drag-handle {
    flex: 0 0 auto; width: 24px; height: 32px; cursor: grab;
    display: grid; place-items: center; color: var(--ap-subtle);
    background: transparent; border: 0; padding: 0;
}
.cat-drag-handle:active { cursor: grabbing; }
.cat-drag-handle svg { width: 16px; height: 16px; }
.cat-row.is-dragging { opacity: 0.5; box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
.cat-row.is-drop-target { box-shadow: inset 0 -2px 0 var(--ap-primary); }

/* Single-select category dropdown — much cleaner than the old multi <select> */
.channel-category-select {
    min-height: 48px;
}

/* Channel-lookup field — pairs with the "lookup" button */
.channel-lookup-row { display: flex; gap: 8px; align-items: stretch; }
.channel-lookup-row .input { flex: 1 1 auto; }
.channel-lookup-row .btn { flex: 0 0 auto; }

/* Channel preview block — appears once a lookup succeeds */
.channel-preview {
    display: flex; gap: 12px; align-items: center;
    padding: 10px 12px; margin-top: 8px;
    border: 1px solid var(--ap-outline); border-radius: 12px; background: #fff;
    min-width: 0;
}
.channel-preview[hidden] { display: none; }
.channel-preview-avatar {
    width: 48px; height: 48px; flex: 0 0 48px;
    border-radius: 50%; overflow: hidden;
    background: var(--ap-primary-soft); color: var(--ap-primary);
    display: grid; place-items: center; font-weight: 700;
}
.channel-preview-avatar img { width: 100%; height: 100%; object-fit: cover; }
.channel-preview-copy { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.channel-preview-copy strong { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.channel-preview-copy small { font-size: 11px; color: var(--ap-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.channel-preview-status { font-size: 11px; color: var(--ap-muted); padding: 4px 8px; border-radius: 999px; background: var(--ap-surface-soft); }
</style>
<header class="page-header"><div><div class="eyebrow">{{ $isFa?'کاتالوگ پیشنهاد به مشتری':'Customer recommendation catalog' }}</div><h1 class="page-title">{{ __('ui.admin_nav.channels') }}</h1><p class="page-lead">{{ $isFa?'فقط یوزرنیم کانال را وارد کنید؛ بقیه اطلاعات از تلگرام گرفته می‌شود. ترتیب دسته‌ها را با درگ تغییر دهید.':'Enter just the channel username; we fetch the rest from Telegram. Drag categories to reorder them.' }}</p></div></header>

<div class="two-column" style="align-items:start;grid-template-columns:minmax(280px,.65fr) minmax(0,1.35fr)">
    <aside class="stack">
        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $isFa?'دسته‌بندی‌ها':'Categories' }}</h2>
                    <p class="card-subtitle number">{{ $categoryItems->count() }} {{ $isFa?'دسته':'total' }}</p>
                </div>
            </div>
            <div class="stack-sm" data-category-list>
            @forelse($categoryItems as $category)
                @php($count=(int)(data_get($category,'channels_count')??collect(data_get($category,'channels',[]))->count()))
                @php($catActive = (bool) data_get($category,'is_active',true))
                @php($editing = old('edit_category_id') === (string) data_get($category,'id'))
                <div class="cat-row" data-category-row data-category-id="{{ data_get($category,'id') }}" draggable="true">
                    <button type="button" class="cat-drag-handle" data-category-drag-handle aria-label="{{ $isFa?'جابجایی':'Drag to reorder' }}" title="{{ $isFa?'برای جابجایی درگ کنید':'Drag to reorder' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>
                    </button>
                    <a class="queue-item" href="{{ $safeRoute('admin.channels.index',['category'=>data_get($category,'slug')]) }}" style="flex:1 1 auto">
                        <span class="queue-icon" style="background:var(--ap-primary-soft);color:var(--ap-primary)"><x-icon name="channel" /></span>
                        <span class="queue-copy">
                            <strong>{{ data_get($category,'title_fa', data_get($category,'title_en','—')) }}</strong>
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
                    <div class="field">
                        <label class="field-label required" for="cat-edit-title-{{ data_get($category,'id') }}">{{ $isFa?'عنوان دسته':'Category title' }}</label>
                        <input class="input" id="cat-edit-title-{{ data_get($category,'id') }}" name="title" value="{{ old('title', data_get($category,'title_fa', data_get($category,'title_en'))) }}" required>
                    </div>
                    <div class="field">
                        <label class="checkbox">
                            <input type="checkbox" name="is_active" value="1" @if(old('is_active',(bool)data_get($category,'is_active',true))) checked @endif>
                            <span>{{ $isFa?'فعال':'Active' }}</span>
                        </label>
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
                <div class="field"><label class="field-label required" for="category-title">{{ $isFa?'عنوان دسته':'Category title' }}</label><input class="input" id="category-title" name="title" required></div>
                <label class="checkbox"><input type="checkbox" name="is_active" value="1" checked><span>{{ $isFa?'فعال':'Active' }}</span></label>
                <button class="btn btn-primary btn-block" type="submit"><x-icon name="plus" />{{ $isFa?'ساخت دسته':'Create category' }}</button>
            </form>
        </section>
    </aside>

    <div class="stack">
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa?'افزودن کانال':'Add channel' }}</h2><p class="card-subtitle">{{ $isFa?'فقط یوزرنیم را وارد کنید، بقیه اطلاعات خودکار از تلگرام گرفته می‌شود':'Just enter the username — the rest is auto-fetched from Telegram' }}</p></div><x-icon name="channel" class="text-primary" /></div>
            <form class="form-grid" action="{{ $safeRoute('admin.channels.store') }}" method="post" data-loading-form data-channel-add-form>
                @csrf
                <div class="field">
                    <label class="field-label required" for="channel-username">{{ $isFa?'یوزرنیم کانال':'Channel username' }}</label>
                    <div class="channel-lookup-row">
                        <input class="input ltr" id="channel-username" name="username" required placeholder="@channel_username" autocomplete="off" data-channel-username-input>
                        <button class="btn btn-secondary" type="button" data-channel-lookup-btn data-channel-lookup-url="{{ $lookupUrl }}">{{ $isFa?'دریافت اطلاعات':'Fetch info' }}</button>
                    </div>
                    <p class="field-help">{{ $isFa?'یوزرنیم، لینک t.me یا آیدی عددی -100... را وارد کنید. بعد از کلیک روی «دریافت اطلاعات»، عنوان، تعداد عضو و عکس از تلگرام گرفته می‌شود. اگر چیزی پیدا نشد، فیلدها خالی می‌مانند و خودتان پر می‌کنید.':'Enter username, t.me link, or numeric -100... chat id. After clicking "Fetch info", title, members, and avatar are pulled from Telegram. If nothing is found, fields stay empty for manual entry.' }}</p>
                </div>

                <div class="channel-preview" data-channel-preview hidden>
                    <span class="channel-preview-avatar" data-channel-preview-avatar></span>
                    <span class="channel-preview-copy">
                        <strong data-channel-preview-title>—</strong>
                        <small class="ltr" data-channel-preview-meta>—</small>
                    </span>
                    <span class="channel-preview-status" data-channel-preview-source></span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="channel-title">{{ $isFa?'عنوان کانال':'Channel title' }}</label>
                        <input class="input" id="channel-title" name="title" placeholder="{{ $isFa?'خودکار از تلگرام پر می‌شود':'Auto-filled from Telegram' }}" data-channel-title-input>
                    </div>
                    <div class="field">
                        <label class="field-label" for="members-count">{{ $isFa?'تعداد عضو':'Member count' }}</label>
                        <input class="input number" id="members-count" name="members_count" type="number" min="0" placeholder="0" data-channel-members-input>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="channel-language">{{ $isFa?'زبان':'Language' }}</label>
                        <select class="select" id="channel-language" name="language" data-channel-language-input>
                            <option value="fa">فارسی</option>
                            <option value="en">English</option>
                            <option value="ar">العربية</option>
                            <option value="other">{{ $isFa?'سایر':'Other' }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label required" for="channel-category">{{ $isFa?'دسته‌بندی':'Category' }}</label>
                        <select class="select channel-category-select" id="channel-category" name="category_ids[]" required>
                            <option value="" disabled selected>{{ $isFa?'یک دسته انتخاب کنید':'Select a category' }}</option>
                            @foreach($categoryItems as $category)
                                @php($catCount = (int) (data_get($category,'channels_count') ?? collect(data_get($category,'channels',[]))->count()))
                                <option value="{{ data_get($category,'id') }}">{{ data_get($category,'title_fa', data_get($category,'title_en')) }} ({{ $catCount }}/30)</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field"><label class="field-label" for="channel-note">{{ $isFa?'یادداشت داخلی (اختیاری)':'Internal note (optional)' }}</label><textarea class="textarea" id="channel-note" name="internal_note" maxlength="1000"></textarea></div>
                <button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ $isFa?'افزودن کانال':'Add channel' }}</button>
            </form>
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
// ─── Category edit-form toggle ───────────────────────────────────────
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

// ─── Drag-and-drop reorder for categories ────────────────────────────
(function () {
    const list = document.querySelector('[data-category-list]');
    if (!list) return;
    let dragging = null;

    list.querySelectorAll('[data-category-row]').forEach((row) => {
        row.addEventListener('dragstart', (e) => {
            dragging = row;
            row.classList.add('is-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', row.dataset.categoryId); } catch (_) {}
        });
        row.addEventListener('dragend', () => {
            if (dragging) dragging.classList.remove('is-dragging');
            list.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
            dragging = null;
        });
        row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            list.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
            row.classList.add('is-drop-target');
        });
        row.addEventListener('drop', async (e) => {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            // Insert dragging before row if dropped above its midpoint, else after.
            const rect = row.getBoundingClientRect();
            const after = (e.clientY - rect.top) > rect.height / 2;
            row.parentNode.insertBefore(dragging, after ? row.nextSibling : row);
            row.classList.remove('is-drop-target');

            // Send the new order to the server.
            const ids = Array.from(list.querySelectorAll('[data-category-row]'))
                .map((el) => parseInt(el.dataset.categoryId, 10))
                .filter((id) => Number.isFinite(id));
            if (ids.length === 0) return;
            try {
                const res = await fetch({{ json_encode($reorderUrl) }}, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ order: ids }),
                });
                if (!res.ok) {
                    alert({{ json_encode($isFa ? 'خطا در ذخیره ترتیب دسته‌ها' : 'Failed to save category order') }});
                }
            } catch (err) {
                alert({{ json_encode($isFa ? 'ارتباط با سرور برقرار نشد' : 'Network error') }});
            }
        });
    });
})();

// ─── Channel auto-lookup (admin "Add channel" form) ─────────────────
//
// IMPORTANT: this IIFE is wrapped in a DOMContentLoaded listener AND in a
// top-level try/catch. The previous version returned silently when any
// element was missing, which meant the admin saw a totally dead "Fetch
// info" button with no console output. Now we always log to the console
// so the operator can debug from the browser dev tools.
(function () {
    const init = () => {
        try {
            const form = document.querySelector('[data-channel-add-form]');
            if (!form) {
                console.warn('[admin.channels] Add-channel form not found on this page — lookup script skipped.');
                return;
            }
            const usernameInput = form.querySelector('[data-channel-username-input]');
            const lookupBtn = form.querySelector('[data-channel-lookup-btn]');
            const titleInput = form.querySelector('[data-channel-title-input]');
            const membersInput = form.querySelector('[data-channel-members-input]');
            const languageInput = form.querySelector('[data-channel-language-input]');
            const preview = form.querySelector('[data-channel-preview]');
            const previewAvatar = form.querySelector('[data-channel-preview-avatar]');
            const previewTitle = form.querySelector('[data-channel-preview-title]');
            const previewMeta = form.querySelector('[data-channel-preview-meta]');
            const previewSource = form.querySelector('[data-channel-preview-source]');

            // ─── Resilient lookupUrl ────────────────────────────────────
            // Previously we read `lookupBtn.dataset.channelLookupUrl`, which
            // is empty when the route cache is stale (production servers
            // with `php artisan route:cache` running on an old build can
            // return `#` for the route). Fall back to a hard-coded path
            // so the button keeps working even when the route helper fails.
            let lookupUrl = lookupBtn?.dataset.channelLookupUrl || '';
            if (!lookupUrl || lookupUrl === '' || lookupUrl === '#') {
                // Build the URL from the current admin prefix. This works
                // regardless of whether the route is cached, and regardless
                // of whether the admin panel is mounted at /admin or at a
                // custom sub-path.
                const adminBase = window.location.pathname.split('/channels')[0] || '/admin';
                lookupUrl = adminBase.replace(/\/$/, '') + '/channels/lookup';
                console.info('[admin.channels] lookup URL rebuilt from path:', lookupUrl);
            }

            if (!usernameInput || !lookupBtn) {
                console.error('[admin.channels] Missing required elements for channel lookup — username input or lookup button not found inside the form.');
                return;
            }

            let controller = null;
            const lookup = async () => {
                const raw = (usernameInput.value || '').trim();
                if (!raw) {
                    usernameInput.focus();
                    return;
                }
                if (controller) controller.abort();
                controller = new AbortController();
                const originalLabel = lookupBtn.textContent;
                lookupBtn.disabled = true;
                lookupBtn.textContent = {{ json_encode($isFa ? 'در حال دریافت...' : 'Fetching...') }};
                try {
                    const params = new URLSearchParams({ q: raw });
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    const res = await fetch(`${lookupUrl}?${params.toString()}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        signal: controller.signal,
                        credentials: 'same-origin',
                    });

                    // 403 = the admin role doesn't have catalog.manage.
                    // Tell the operator exactly what's wrong instead of a
                    // generic "failed" toast.
                    if (res.status === 403) {
                        alert({{ json_encode($isFa ? 'شما دسترسی مدیریت کاتالوگ ندارید. با مدیر سیستم تماس بگیرید.' : 'You do not have catalog.manage permission. Contact the system admin.') }});
                        return;
                    }
                    if (res.status === 404) {
                        alert({{ json_encode($isFa ? 'کانال یافت نشد. یوزرنیم را بررسی کنید یا اطلاعات را دستی وارد کنید.' : 'Channel not found. Check the username or fill in manually.') }});
                        return;
                    }
                    if (res.status === 422) {
                        // Validation error — Laravel returns {message, errors}.
                        let validationMessage = {{ json_encode($isFa ? 'ورودی نامعتبر است.' : 'Invalid input.') }};
                        try {
                            const errBody = await res.json();
                            if (errBody && errBody.message) validationMessage = errBody.message;
                        } catch (_) {}
                        alert(validationMessage);
                        return;
                    }
                    if (!res.ok) {
                        console.error('[admin.channels] lookup failed', { status: res.status, statusText: res.statusText });
                        alert({{ json_encode($isFa ? 'خطا در دریافت اطلاعات کانال (کد ' : 'Failed to fetch channel info (code ') }} + res.status + ')');
                        return;
                    }

                    // Some misconfigured servers return HTML (the admin
                    // login page) when the session expires mid-request.
                    // Detect this and prompt the admin to re-login instead
                    // of crashing on res.json().
                    const contentType = res.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        console.error('[admin.channels] lookup returned non-JSON content-type:', contentType);
                        alert({{ json_encode($isFa ? 'نشست شما منقضی شده است. دوباره وارد شوید.' : 'Your admin session has expired. Please log in again.') }});
                        // Force a re-login by redirecting to the login page.
                        window.location.href = (window.location.pathname.split('/channels')[0] || '/admin') + '/login';
                        return;
                    }

                    const data = await res.json();
                    if (!data || (!data.username && !data.title && !data.error)) {
                        console.warn('[admin.channels] lookup returned empty payload', data);
                        return;
                    }
                    if (data.error) {
                        // Server-side error returned as JSON.
                        if (data.error === 'not_found') {
                            alert({{ json_encode($isFa ? 'کانال یافت نشد. یوزرنیم را بررسی کنید یا اطلاعات را دستی وارد کنید.' : 'Channel not found. Check the username or fill in manually.') }});
                        } else if (data.error === 'invalid') {
                            alert({{ json_encode($isFa ? 'یوزرنیم نامعتبر است. باید حداقل ۴ کاراکتر و فقط شامل حروف انگلیسی، عدد و زیرخط باشد.' : 'Invalid username. Must be 4-64 chars, English letters/digits/underscore only.') }});
                        } else {
                            alert({{ json_encode($isFa ? 'خطا: ' : 'Error: ') }} + data.error);
                        }
                        return;
                    }
                    if (data.title && titleInput && !titleInput.value) titleInput.value = data.title;
                    if (data.members != null && membersInput && !membersInput.value) membersInput.value = String(data.members);
                    if (data.language && languageInput) languageInput.value = data.language;
                    // Also sync the username field so the form submits a
                    // clean value (without the leading @ or t.me/ prefix).
                    if (data.username) usernameInput.value = data.username;

                    // Show preview block
                    if (preview) {
                        preview.hidden = false;
                        previewAvatar.innerHTML = '';
                        if (data.avatar) {
                            const img = document.createElement('img');
                            img.src = data.avatar;
                            img.alt = '';
                            img.loading = 'lazy';
                            previewAvatar.appendChild(img);
                        } else {
                            previewAvatar.textContent = (data.title || data.username || '?').trim().charAt(0).toUpperCase();
                        }
                        if (previewTitle) previewTitle.textContent = data.title || data.username || '—';
                        if (previewMeta) {
                            const parts = [];
                            if (data.username) parts.push('@' + data.username);
                            if (data.members != null) parts.push((new Intl.NumberFormat('fa-IR')).format(data.members) + ' ' + {{ json_encode($isFa ? 'عضو' : 'members') }});
                            previewMeta.textContent = parts.join(' · ');
                        }
                        if (previewSource) {
                            previewSource.textContent = data.source === 'catalog'
                                ? {{ json_encode($isFa ? 'از کاتالوگ' : 'From catalog') }}
                                : {{ json_encode($isFa ? 'از تلگرام' : 'From Telegram') }};
                        }
                    }
                } catch (err) {
                    if (err && err.name === 'AbortError') return;
                    console.error('[admin.channels] lookup network error', err);
                    alert({{ json_encode($isFa ? 'ارتباط با سرور برقرار نشد: ' : 'Network error: ') }} + (err?.message || err));
                } finally {
                    lookupBtn.disabled = false;
                    lookupBtn.textContent = originalLabel;
                }
            };
            lookupBtn.addEventListener('click', lookup);
            // Auto-lookup on blur (if the field has a value and the preview is empty).
            usernameInput.addEventListener('blur', () => {
                if ((usernameInput.value || '').trim() && preview && preview.hidden) lookup();
            });
            // Also trigger when the admin presses Enter inside the username field
            // (don't submit the whole form — just lookup).
            usernameInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    lookup();
                }
            });
            // Tag the form so app.js's generic form-submit handler can detect
            // that lookup is wired up (helps with debugging).
            form.setAttribute('data-channel-lookup-ready', '');
        } catch (e) {
            console.error('[admin.channels] Fatal error while initializing channel lookup', e);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        // DOM already parsed (we're inside a deferred Vite bundle) — run now.
        init();
    }
})();
</script>
@endsection
