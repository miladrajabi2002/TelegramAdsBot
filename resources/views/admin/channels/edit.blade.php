@extends('layouts.admin')

@section('title', app()->isLocale('fa') ? 'ویرایش کانال' : 'Edit channel')
@section('page-title', app()->isLocale('fa') ? 'ویرایش کانال پیشنهادی' : 'Edit suggested channel')

@section('content')
@php($isFa = app()->isLocale('fa'))
@php($lookupUrl = \Illuminate\Support\Facades\Route::has('admin.channels.lookup') ? route('admin.channels.lookup') : '#')
<header class="page-header">
    <div><div class="eyebrow ltr">{{ '@'.$channel->username }}</div><h1 class="page-title">{{ $channel->title }}</h1><p class="page-lead">{{ $isFa ? 'یوزرنیم را تغییر دهید و برای دریافت خودکار اطلاعات از تلگرام، دکمه «دریافت از تلگرام» را بزنید. دسته‌ها و وضعیت فعال بودن کانال را نیز اینجا مدیریت کنید.' : 'Change the username and click "Refresh from Telegram" to auto-pull title, members, and avatar. Manage categories and active state here too.' }}</p></div>
    <div class="page-header-actions"><a class="btn btn-secondary" href="{{ route('admin.channels.index') }}">{{ $isFa ? 'بازگشت' : 'Back' }}</a></div>
</header>

<style>
.channel-lookup-row { display: flex; gap: 8px; align-items: stretch; }
.channel-lookup-row .input { flex: 1 1 auto; }
.channel-lookup-row .btn { flex: 0 0 auto; }
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

<section class="card" style="max-width:820px">
    <form class="form-grid" action="{{ route('admin.channels.update', $channel) }}" method="post" data-loading-form data-channel-edit-form>
        @csrf @method('PUT')
        <div class="field">
            <label class="field-label required" for="channel-username">Username</label>
            <div class="channel-lookup-row">
                <input class="input ltr" id="channel-username" name="username" required pattern="[A-Za-z0-9_]{5,32}" value="{{ old('username', $channel->username) }}" data-channel-username-input>
                <button class="btn btn-secondary" type="button" data-channel-lookup-btn data-channel-lookup-url="{{ $lookupUrl }}">{{ $isFa ? 'دریافت از تلگرام' : 'Refresh from Telegram' }}</button>
            </div>
            <p class="field-help">{{ $isFa ? 'برای دریافت خودکار عنوان، تعداد عضو و عکس از تلگرام، دکمه را بزنید. یوزرنیم، لینک t.me یا آیدی عددی هم کار می‌کند.' : 'Click the button to auto-pull title, members, and avatar from Telegram. Username, t.me link, or numeric id all work.' }}</p>
        </div>

        <div class="channel-preview" data-channel-preview @if(!$channel->avatar_url) hidden @endif>
            <span class="channel-preview-avatar" data-channel-preview-avatar>
                @if($channel->avatar_url)<img src="{{ $channel->avatar_url }}" alt="">@else{{ mb_strtoupper(mb_substr((string) $channel->title, 0, 1)) }}@endif
            </span>
            <span class="channel-preview-copy">
                <strong data-channel-preview-title>{{ old('title', $channel->title) }}</strong>
                <small class="ltr" data-channel-preview-meta>@{{ $channel->username }} · {{ number_format((int) $channel->members_count) }} {{ $isFa ? 'عضو' : 'members' }}</small>
            </span>
            <span class="channel-preview-status" data-channel-preview-source>{{ $isFa ? 'ذخیره‌شده' : 'Saved' }}</span>
        </div>

        <input type="hidden" name="refresh_from_telegram" value="0" data-channel-refresh-flag>

        <div class="field-row">
            <div class="field"><label class="field-label" for="channel-title">{{ $isFa ? 'عنوان کانال' : 'Channel title' }}</label><input class="input" id="channel-title" name="title" maxlength="150" value="{{ old('title', $channel->title) }}" placeholder="{{ $isFa ? 'خودکار از تلگرام پر می‌شود' : 'Auto-filled from Telegram' }}" data-channel-title-input></div>
            <div class="field"><label class="field-label" for="channel-members">{{ $isFa ? 'تعداد عضو' : 'Member count' }}</label><input class="input number" id="channel-members" name="members_count" type="number" min="0" value="{{ old('members_count', $channel->members_count) }}" data-channel-members-input></div>
        </div>
        <div class="field-row">
            <div class="field"><label class="field-label required" for="channel-language">{{ $isFa ? 'زبان' : 'Language' }}</label><select class="select" id="channel-language" name="language" required data-channel-language-input>@foreach(['fa'=>'فارسی','en'=>'English','ar'=>'العربية','other'=>'Other'] as $value=>$label)<option value="{{ $value }}" @selected(old('language', $channel->language)===$value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="field"><label class="field-label required" for="channel-categories">{{ $isFa ? 'دسته‌بندی‌ها' : 'Categories' }}</label><select class="select" id="channel-categories" name="category_ids[]" multiple required style="min-height:150px">@foreach($categories as $category)<option value="{{ $category->id }}" @selected(in_array($category->id, old('category_ids', $channel->categories->pluck('id')->all())))>{{ $isFa ? $category->title_fa : $category->title_en }} ({{ $category->channels_count }}/30)</option>@endforeach</select></div>
        <div class="field"><label class="field-label" for="channel-note">{{ $isFa ? 'یادداشت داخلی' : 'Internal note' }}</label><textarea class="textarea" id="channel-note" name="internal_note" maxlength="1000">{{ old('internal_note', $channel->internal_note) }}</textarea></div>
        <div class="cluster"><button class="btn btn-primary" type="submit"><x-icon name="check" />{{ $isFa ? 'ذخیره تغییرات' : 'Save changes' }}</button></div>
    </form>
    <form action="{{ route('admin.channels.toggle', $channel) }}" method="post" data-loading-form style="margin-top:14px">@csrf<button class="btn {{ $channel->is_active ? 'btn-danger' : 'btn-secondary' }}" type="submit">{{ $channel->is_active ? ($isFa ? 'غیرفعال‌کردن کانال' : 'Disable channel') : ($isFa ? 'فعال‌کردن کانال' : 'Enable channel') }}</button></form>
</section>

<script>
(function () {
    const form = document.querySelector('[data-channel-edit-form]');
    if (!form) return;
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
    const refreshFlag = form.querySelector('[data-channel-refresh-flag]');
    const lookupUrl = lookupBtn?.dataset.channelLookupUrl;
    if (!usernameInput || !lookupBtn || !lookupUrl || lookupUrl === '#') return;

    const isFa = {{ json_encode($isFa) }};
    let controller = null;
    const lookup = async () => {
        const raw = (usernameInput.value || '').trim();
        if (!raw) { usernameInput.focus(); return; }
        if (controller) controller.abort();
        controller = new AbortController();
        const originalLabel = lookupBtn.textContent;
        lookupBtn.disabled = true;
        lookupBtn.textContent = isFa ? 'در حال دریافت...' : 'Fetching...';
        try {
            const params = new URLSearchParams({ q: raw });
            const res = await fetch(`${lookupUrl}?${params}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                signal: controller.signal, credentials: 'same-origin',
            });
            if (res.status === 404) {
                alert(isFa ? 'کانال یافت نشد. یوزرنیم را بررسی کنید.' : 'Channel not found.');
                return;
            }
            if (!res.ok) {
                alert(isFa ? 'خطا در دریافت اطلاعات.' : 'Failed to fetch.');
                return;
            }
            const data = await res.json();
            if (!data) return;
            if (data.title && titleInput) titleInput.value = data.title;
            if (data.members != null && membersInput) membersInput.value = String(data.members);
            if (data.language && languageInput) languageInput.value = data.language;

            if (preview) {
                preview.hidden = false;
                previewAvatar.innerHTML = '';
                if (data.avatar) {
                    const img = document.createElement('img');
                    img.src = data.avatar; img.alt = ''; img.loading = 'lazy';
                    previewAvatar.appendChild(img);
                } else {
                    previewAvatar.textContent = (data.title || data.username || '?').trim().charAt(0).toUpperCase();
                }
                if (previewTitle) previewTitle.textContent = data.title || data.username || '—';
                if (previewMeta) {
                    const parts = [];
                    if (data.username) parts.push('@' + data.username);
                    if (data.members != null) parts.push((new Intl.NumberFormat(isFa ? 'fa-IR' : 'en-US')).format(data.members) + ' ' + (isFa ? 'عضو' : 'members'));
                    previewMeta.textContent = parts.join(' · ');
                }
                if (previewSource) {
                    previewSource.textContent = data.source === 'catalog' ? (isFa ? 'از کاتالوگ' : 'From catalog') : (isFa ? 'از تلگرام' : 'From Telegram');
                }
            }
            // Mark refresh flag so the server re-pulls from Telegram too
            // (covers cases where the bot has more recent info than the lookup).
            if (refreshFlag) refreshFlag.value = '1';
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            alert(isFa ? 'ارتباط با سرور برقرار نشد' : 'Network error');
        } finally {
            lookupBtn.disabled = false;
            lookupBtn.textContent = originalLabel;
        }
    };
    lookupBtn.addEventListener('click', lookup);
    usernameInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); lookup(); }
    });
})();
</script>
@endsection
