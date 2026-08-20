@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'ثبت تبلیغ' : 'Create campaign') . ' — ' . __('ui.brand'))

@php
// Add .has-wizard-action to .mini-content so the layout reserves
// enough bottom padding for BOTH the bottom nav AND the sticky
// wizard action bar (Continue / Back). Without this, the last
// fields of each step get hidden behind the action bar on mobile.
// View::share is used because variables defined in a child Blade
// template are not propagated to the parent layout automatically.
\Illuminate\Support\Facades\View::share('contentModifiers', 'has-wizard-action');
@endphp




@push('head')
<style>
    /* Campaign wizard only. Telegram/iOS may shrink AND vertically pan the
   visual viewport when the software keyboard opens. The previous fix used
   only visualViewport.height, which over-counted the keyboard whenever
   offsetTop was non-zero and pushed the action bar up under the header.

   We now compensate only for the actually OCCLUDED bottom area:
       the visual viewport pan (offsetTop) and/or a real layout-viewport shrink.
   Both fixed bottom layers move together, so their normal spacing is kept.
   The controls stay anchored to the physical bottom and can sit behind the
   keyboard while typing instead of jumping into the form. */
    html.campaign-create-keyboard-open .mini-bottom-nav,
    html.campaign-create-keyboard-open .wizard-actions {
        transform: translateY(var(--campaign-create-keyboard-shift, 0px));
        transition: none !important;
    }
</style>
@endpush

@section('content')
@php
$isFa = app()->isLocale('fa');
$safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
$editing = (bool) ($editing ?? false);
$draft = $draft ?? $campaign ?? $order ?? null;
$draftRevision = data_get($draft, 'currentRevision', data_get($draft, 'current_revision', []));
$draftId = data_get($draft, 'public_id', data_get($draft, 'id'));
$draftTargets = collect(data_get($draftRevision, 'targets', data_get($draftRevision, 'targetChannels', data_get($draftRevision, 'target_channels', []))));
$selectedTargetIds = $draftTargets
->map(fn ($channel) => (string) data_get($channel, 'suggested_channel_id', data_get($channel, 'channel_id', data_get($channel, 'id'))))
->filter()
->values()
->all();
$existingManualChannels = $draftTargets->filter(fn ($target) => data_get($target, 'source') === 'manual')
->pluck('channel_username')->filter()->map(fn ($username) => '@'.ltrim((string) $username, '@'))->implode("\n");
$categoryItems = collect($categories ?? []);
$channelRows = [];
foreach ($categoryItems as $category) {
$slug = (string) data_get($category, 'slug', data_get($category, 'id', 'other'));
foreach (collect(data_get($category, 'channels', [])) as $channel) {
$key = (string) data_get($channel, 'id', data_get($channel, 'username'));
if (!isset($channelRows[$key])) $channelRows[$key] = ['channel' => $channel, 'categories' => []];
$channelRows[$key]['categories'][] = $slug;
}
}
foreach (collect($suggestedChannels ?? []) as $channel) {
$key = (string) data_get($channel, 'id', data_get($channel, 'username'));
$slugs = collect(data_get($channel, 'categories', []))->map(fn ($category) => (string) data_get($category, 'slug', data_get($category, 'id')))->filter()->values()->all();
if (!isset($channelRows[$key])) $channelRows[$key] = ['channel' => $channel, 'categories' => $slugs ?: ['other']];
}
$quoteData = $quote ?? [];
$mediaToman = (int) data_get($quoteData, 'media_budget_toman', data_get($defaults ?? [], 'media_budget_toman', 100000));
$servicePercent = (float) data_get($quoteData, 'service_markup_percent', data_get($defaults ?? [], 'service_markup_percent', 15));
$serviceToman = (int) data_get($quoteData, 'service_fee_toman', round($mediaToman * $servicePercent / 100));
$gatewayToman = (int) data_get($quoteData, 'gateway_fee_toman', 0);
$totalToman = (int) data_get($quoteData, 'total_toman', $mediaToman + $serviceToman + $gatewayToman);
$totalUsd = (float) data_get($quoteData, 'total_usd', 0);
$currentPlacement = old('placement_type', data_get($draftRevision, 'placement_type', 'channel_posts'));
$dailyViewDefault = (int) old('daily_view_limit_per_user', data_get($draftRevision, 'daily_view_limit_per_user', 1));
$existingKeywords = collect(old('search_keywords', data_get($draftRevision, 'search_keywords', [])))->map(fn ($k) => (string) $k)->filter()->values()->all();
@endphp

<header class="page-header">
    <div>
        <div class="eyebrow">{{ $isFa ? 'ساخت مرحله‌به‌مرحله' : 'Guided setup' }}</div>
        <h1 class="page-title">{{ $isFa ? 'ثبت تبلیغ جدید' : 'Create a campaign' }}</h1>
        <p class="page-lead">{{ $isFa ? 'اطلاعات را قدم‌به‌قدم وارد کنید؛ پیش‌نویس شما تا زمان ثبت نهایی محفوظ می‌ماند.' : 'Add the details one step at a time. Your draft stays safe until submission.' }}</p>
    </div>
</header>

<form action="{{ $editing ? ($draftId ? $safeRoute('app.campaigns.update', ['campaign' => $draftId]) : '#') : $safeRoute('app.campaigns.store') }}" method="post" enctype="multipart/form-data" class="wizard-shell" data-wizard data-loading-form data-telegram-auth data-campaign-order-wizard data-wizard-total-steps="{{ $editing ? 5 : 6 }}"
    @php
    // ─── On validation failure, jump to the step containing the FIRST
    // errorred field instead of always starting from step 1.
    // Without this, a user who submits the wizard and gets a server-
    // side validation error on (say) step 4 lands back on step 1 with
    // the error notice shown above the wizard — they have no idea
    // which step the error belongs to and have to walk forward to find it.
    //
    // We map each form field name to its 1-indexed wizard step number,
    // then pick the step of the FIRST errored field the user encounters.
    $initialStep=1;
    if (isset($errors) && $errors->any()) {
    $fieldToStep = [
    'internal_title' => 1, 'destination_url' => 1,
    'placement_type' => 2, 'ad_text' => 2, 'ad_media' => 2,
    'search_keywords' => 2,
    'target_channel_ids' => 3, 'manual_channels' => 3,
    'media_budget_toman' => 4, 'cpm_gram' => 4, 'impression_goal' => 4,
    'frequency_cap' => 4, 'daily_view_limit_per_user' => 4, 'plan' => 4,
    'language' => 4, 'media_budget_gram' => 4,
    'planned_start_at' => 5,
    'terms_accepted' => 5, 'payment' => 5,
    ];
    foreach ($errors->keys() as $key) {
    if (isset($fieldToStep[$key])) {
    $initialStep = $fieldToStep[$key];
    break;
    }
    }
    }
    @endphp
    data-initial-step="{{ $initialStep }}">
    @csrf
    @if($editing) @method('PUT') @endif
    <div class="wizard-progress" aria-label="{{ $isFa ? 'پیشرفت ثبت کمپین' : 'Campaign setup progress' }}">
        <div class="wizard-progress-meta"><span>{{ $isFa ? 'مرحله' : 'Step' }} <b data-wizard-current>1</b> {{ $isFa ? 'از' : 'of' }} {{ $editing ? 5 : 6 }}</span><span>{{ $isFa ? 'ذخیره خودکار پیش‌نویس' : 'Draft autosave ready' }}</span></div>
        <div class="progress"><span data-wizard-progress style="--progress:{{ $editing ? '20%' : '16.6667%' }}"></span></div>
    </div>

    {{-- ─── Step 1 — Title + Ad link (placement moved to step 2) ─── --}}
    <section class="wizard-pane card" data-wizard-step>
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'عنوان و هدف' : 'Title & target' }}</h2>
                <p class="card-subtitle">{{ $isFa ? 'ابتدا عنوان و لینک مقصد را وارد کنید؛ نوع تبلیغ در مرحله بعد انتخاب می‌شود.' : 'Pick the title and destination; ad type is chosen next.' }}</p>
            </div><span class="chip">1</span>
        </div>
        <div class="form-grid">
            <div class="field">
                <label class="field-label required" for="internal-title">{{ $isFa ? 'عنوان تبلیغ' : 'Internal title' }}</label>
                <input class="input" id="internal-title" name="internal_title" required maxlength="100" value="{{ old('internal_title', data_get($draftRevision, 'internal_title')) }}" placeholder="{{ $isFa ? 'مثلاً جذب عضو برای کانال فروشگاه' : 'e.g. Grow our store channel' }}">
                <p class="field-help">{{ $isFa ? 'مخاطبان این عنوان را نمی‌بینند.' : 'Your audience will not see this title.' }}</p>
            </div>
            <div class="field">
                <label class="field-label required" for="destination-url">{{ $isFa ? 'لینکی که می‌خواهید تبلیغ کنید' : 'Link to advertise' }}</label>
                <input class="input ltr" id="destination-url" name="destination_url" type="url" required value="{{ old('destination_url', data_get($draftRevision, 'destination_url')) }}" placeholder="https://t.me/your_channel" inputmode="url" data-field-validator="destination_url" autocomplete="off" spellcheck="false">
                <p class="field-help">{{ $isFa ? 'لینک کانال، ربات یا صفحه‌ای که می‌خواهید تبلیغ کنید را دقیق وارد کنید.' : 'Enter the exact link you want to advertise.' }}</p>
                {{-- Inline error placeholder — filled by app.js (data-field-validator) AND by the server-side @error block below it. The JS writes the same message the server would, so the user sees the error at the field immediately, not at the end of the wizard. --}}
                <p class="field-error" data-inline-error-for="destination_url" hidden></p>
                @error('destination_url')<p class="field-error" data-server-error="destination_url">{{ $message }}</p>@enderror
            </div>
        </div>
    </section>

    {{-- ─── Step 2 — Placement type + Ad content (16:9 media, live preview) ─── --}}
    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'محتوای تبلیغ' : 'Ad content' }}</h2>
                <p class="card-subtitle">{{ $isFa ? 'نوع تبلیغ، متن و رسانه را وارد کنید؛ پیش‌نمایش زنده تغییر می‌کند.' : 'Pick ad type, copy and media; preview updates live.' }}</p>
            </div><span class="chip">2</span>
        </div>

        <div class="notice notice-warning" data-placement-notice data-placement-for="all">
            <x-icon name="lock" />
            <div><strong>{{ $isFa ? 'پارامترهای هدف بعد از ایجاد شدن نمی‌تواند تغییر کند.' : 'Target parameters cannot be changed after creation.' }}</strong>
                <p>{{ $isFa ? 'پس از ثبت نهایی، نوع هدف، کلیدواژه‌ها و فایل‌های پیوست قابل ویرایش نخواهند بود.' : 'Once submitted, the target type, keywords, and attached media cannot be edited.' }}</p>
            </div>
        </div>

        <div class="field" style="margin-top:14px">
            <span class="field-label required">{{ $isFa ? 'محل نمایش تبلیغ' : 'Ad placement' }}</span>
            <div class="placement-grid" role="radiogroup" aria-label="{{ $isFa ? 'محل نمایش تبلیغ' : 'Ad placement' }}" data-placement-group>
                <label class="placement-card"><input type="radio" name="placement_type" value="channel_posts" required @checked($currentPlacement==='channel_posts' ) data-placement-option><span class="placement-card-copy"><span class="quick-icon"><x-icon name="channel" /></span><strong>{{ $isFa ? 'کانال‌ها' : 'Channels' }}</strong><small>{{ $isFa ? 'نمایش در پست کانال‌های Telegram' : 'Shown in channel posts' }}</small></span></label>
                <label class="placement-card"><input type="radio" name="placement_type" value="bot_messages" required @checked($currentPlacement==='bot_messages' ) data-placement-option><span class="placement-card-copy"><span class="quick-icon"><x-icon name="send" /></span><strong>{{ $isFa ? 'ربات‌ها' : 'Bots' }}</strong><small>{{ $isFa ? 'نمایش در پیام ربات‌ها' : 'Shown in bot messages' }}</small></span></label>
                <label class="placement-card"><input type="radio" name="placement_type" value="search_results" required @checked($currentPlacement==='search_results' ) data-placement-option><span class="placement-card-copy"><span class="quick-icon"><x-icon name="search" /></span><strong>{{ $isFa ? 'جستجو' : 'Search' }}</strong><small>{{ $isFa ? 'نمایش در نتایج جستجو' : 'Shown in search results' }}</small></span></label>
            </div>
            <p class="field-help">{{ $isFa ? 'با تغییر گزینه، پیش‌نمایش همان لحظه به‌روزرسانی می‌شود.' : 'Switching the option updates the live preview instantly.' }}</p>
        </div>

        <div class="two-column ad-content-layout">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label required" for="ad-text">{{ $isFa ? 'متن تبلیغ' : 'Ad text' }}</label>
                    <textarea class="textarea" id="ad-text" name="ad_text" required maxlength="160" data-count-target="#ad-text-counter" data-preview-target="#ad-preview-text" data-field-validator="ad_text" placeholder="{{ $isFa ? 'متن شفاف، کوتاه و دقیق بنویسید.' : 'Write a clear, concise message.' }}" inputmode="text">{{ old('ad_text', data_get($draftRevision, 'ad_text')) }}</textarea>
                    <div class="counter number" id="ad-text-counter">0 / 160</div>
                    <p class="field-help">{{ $isFa ? 'استفاده از ایموجی مجاز است. شکست خط و لینک اضافی مجاز نیست.' : 'Emoji allowed. No line breaks or extra links.' }}</p>
                    {{-- Inline error placeholder — filled by app.js (data-field-validator) AND by the server-side @error block below it. Same idea as destination_url: tell the user the moment they type a second link or press Enter for a line break, instead of waiting until the final submit. --}}
                    <p class="field-error" data-inline-error-for="ad_text" hidden></p>
                    @error('ad_text')<p class="field-error" data-server-error="ad_text">{{ $message }}</p>@enderror
                </div>

                {{-- Image / video upload — only for کانال ها (channel_posts) --}}
                <div class="field" data-placement-field="channel_posts">
                    <span class="field-label">{{ $isFa ? 'افزودن تصویر یا ویدیو (اختیاری)' : 'Add image or video (optional)' }}</span>
                    <label class="upload-box upload-box-ad-media" for="ad-media">
                        <input id="ad-media" name="ad_media" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" data-preview-input="#ad-media-preview" data-media-preview-target="#ad-preview-media" data-media-ratio="16:9">
                        <span class="upload-box-content"><span class="quick-icon"><x-icon name="upload" /></span><strong>{{ $isFa ? 'انتخاب یا گرفتن عکس/ویدیو' : 'Choose or take a photo/video' }}</strong><small class="muted">{{ $isFa ? 'عکس (JPG/PNG/WebP) یا ویدیو (MP4/MOV/WebM) تا ۵۰ مگابایت — نسبت ۱۶:۹' : 'Image (JPG/PNG/WebP) or video (MP4/MOV/WebM) up to 50MB — 16:9 ratio' }}</small></span>
                        <img class="upload-preview" id="ad-media-preview" alt="">
                        <video class="upload-preview upload-preview-video" id="ad-media-preview-video" muted playsinline hidden></video>
                    </label>
                    <p class="field-help">{{ $isFa ? 'رسانه باید نسبت ۱۶:۹ (افقی) داشته باشد؛ تصاویر مربع یا عمودی رد می‌شوند. رزولوشن پیشنهادی حداقل ۱۲۸۰×۷۲۰.' : 'Media must have a 16:9 (landscape) aspect ratio; square or vertical media will be rejected. Recommended resolution at least 1280×720.' }}</p>
                    @if(data_get($draftRevision, 'ad_media_path'))
                    <p class="field-help">{{ $isFa ? 'رسانه موجود: '.data_get($draftRevision, 'ad_media_path') : 'Existing media: '.data_get($draftRevision, 'ad_media_path') }}</p>
                    @endif
                </div>

                {{-- Search keywords — only for جستجو (search_results) --}}
                <div class="field" data-placement-field="search_results" hidden>
                    <label class="field-label required" for="search-keyword-input">{{ $isFa ? 'جستجوی هدف' : 'Search keywords' }}</label>
                    <div class="keyword-search" data-keyword-search data-min-length="4" data-max="30">
                        <div class="keyword-search-input-wrap">
                            <input type="text" id="search-keyword-input" class="keyword-search-input" placeholder="{{ $isFa ? 'کلیدواژه را تایپ و Enter بزنید — حداقل ۴ نویسه' : 'Type a keyword and press Enter (min 4 chars)' }}" autocomplete="off" data-keyword-search-input>
                        </div>
                        <p class="field-help">{{ $isFa ? 'هر کلیدواژه حداقل ۴ نویسه باشد. می‌توانید چندین کلیدواژه اضافه کنید.' : 'Each keyword must be at least 4 characters. Multiple keywords are allowed.' }}</p>
                        <div class="keyword-search-results" data-keyword-search-results></div>
                        <p class="keyword-search-empty" data-keyword-search-empty hidden>{{ $isFa ? 'هنوز کلیدواژه‌ای اضافه نشده است.' : 'No keywords added yet.' }}</p>
                        <div data-keyword-search-hidden>
                            @foreach($existingKeywords as $kw)
                            <input type="hidden" name="search_keywords[]" value="{{ $kw }}">
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Daily view limit per user — 4-button selector, applies to ALL 3 placements --}}
                <div class="field">
                    <span class="field-label required">{{ $isFa ? 'محدودیت بازدید روزانه برای هر کاربر' : 'Daily view limit per user' }}</span>
                    <div class="frequency-grid" role="radiogroup" data-frequency-group>
                        @for($i = 1; $i <= 4; $i++)
                            <label class="frequency-card"><input type="radio" name="daily_view_limit_per_user" value="{{ $i }}" required @checked($dailyViewDefault===$i) data-frequency-option><span class="frequency-card-copy"><strong class="number">{{ $i }}</strong><small>{{ $isFa ? 'بار در روز' : ($i === 1 ? 'time/day' : 'times/day') }}</small></span></label>
                            @endfor
                    </div>
                </div>
            </div>

            {{-- iOS-style Telegram preview — switches per placement_type
                Redesigned Aug 2026 to look closer to native Telegram mobile UI.
                Each placement (channel / bot / search) has its own visual language:
                  • channel → Telegram channel feed post with "Ad" badge + CTA
                  • bot     → Telegram bot chat with sponsored bubble + inline keyboard button
                  • search  → Telegram search results list with sponsored row
                The wrapper keeps the original data-* attributes so the existing
                JS in app.js (data-preview-stage, data-preview-view, etc.) keeps
                working unchanged.
            --}}
            @include('app.campaigns.partials.telegram-native-preview')
        </div>
    </section>

    {{-- ─── Step 3 — Target channels/bots/keywords (label depends on placement) ─── --}}
    <section class="wizard-pane card" data-wizard-step hidden data-channel-picker>
        <div class="card-head">
            <div>
                <h2 class="card-title" data-target-step-title>{{ $isFa ? 'کانال‌های هدف' : 'Target channels' }}</h2>
                <p class="card-subtitle" data-target-step-subtitle>{{ $isFa ? 'آیدی کانال یا ربات را با Enter اضافه کنید. حذف با دکمه ×. حداقل یک کانال الزامی است.' : 'Add a channel or bot ID with Enter. Remove with ×. At least one is required.' }}</p>
            </div>
            <span class="chip">3</span>
        </div>

        @php($channelSearchUrl = \Illuminate\Support\Facades\Route::has('app.channels.search') ? route('app.channels.search') : null)
        @if($channelSearchUrl)
        <div class="field" style="margin-top:6px">
            <label class="field-label required" for="channel-search-input" data-search-input-label>{{ $isFa ? 'جستجوی کانال یا ربات' : 'Search channel or bot' }}</label>
            <div class="channel-search" data-channel-search="{{ $channelSearchUrl }}" data-channel-search-locale="{{ app()->getLocale() }}">
                <input type="text" id="channel-search-input" class="channel-search-input ltr" placeholder="{{ $isFa ? '@یوزرنیم · یوزرنیم بدون @ · https://t.me/... · آیدی عددی -100...' : '@username · username · https://t.me/... · -1001234567890' }}" autocomplete="off" data-channel-search-input aria-describedby="channel-search-help">
                <p class="field-help" id="channel-search-help">{{ $isFa ? 'یوزرنیم (با @ یا بدون @)، لینک t.me، یا آیدی عددی -100... را وارد کنید و Enter یا ویرگول بزنید. اطلاعات کانال (عکس، عنوان، تعداد عضو و آیدی) خودکار نمایش داده می‌شود. برای حذف، روی دکمه × هر کانال بزنید.' : 'Enter @username, plain username, t.me link, or numeric -100... chat id, then press Enter or comma. The channel photo, title, members, and id are fetched automatically. Tap × on any chip to remove it.' }}</p>
                <div class="channel-search-results" data-channel-search-results aria-live="polite"></div>
                <p class="channel-search-empty" data-channel-search-empty hidden>{{ $isFa ? 'هنوز کانالی اضافه نکرده‌اید.' : 'No channels added yet.' }}</p>
                <div data-channel-search-hidden>
                    @foreach(old('target_channel_ids', $selectedTargetIds ?? []) as $id)
                    <input type="hidden" name="target_channel_ids[]" value="{{ $id }}">
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        @if($categoryItems->isNotEmpty())
        <div class="category-tabs" role="group" aria-label="{{ $isFa ? 'دسته‌بندی کانال‌ها' : 'Channel categories' }}">
            <button class="category-tab is-active" type="button" data-category-filter="all" aria-pressed="true">{{ __('ui.common.all') }}</button>
            @foreach($categoryItems as $category)
            @php($slug = (string) data_get($category, 'slug', data_get($category, 'id')))
            <button class="category-tab" type="button" data-category-filter="{{ $slug }}" aria-pressed="false">{{ $isFa ? data_get($category, 'title_fa', data_get($category, 'title_en')) : data_get($category, 'title_en', data_get($category, 'title_fa')) }} <span class="number">{{ collect(data_get($category, 'channels', []))->count() }}/30</span></button>
            @endforeach
        </div>
        @endif

        @if(count($channelRows))
        <div class="channel-list" style="margin-top:10px" data-channel-list>
            @foreach($channelRows as $row)
            @php($channel = $row['channel'])
            <label class="channel-card" data-channel-category="{{ implode(',', array_unique($row['categories'])) }}">
                <input type="checkbox" name="target_channel_ids[]" value="{{ data_get($channel, 'id', data_get($channel, 'username')) }}" @checked(in_array((string) data_get($channel, 'id' , data_get($channel, 'username' )), array_map('strval', old('target_channel_ids', $selectedTargetIds)), true))>
                <span class="channel-card-avatar">
                    @if(data_get($channel, 'avatar_url'))<img src="{{ data_get($channel, 'avatar_url') }}" alt="" loading="lazy">@else<span class="channel-card-avatar-fallback">{{ mb_strtoupper(mb_substr((string) data_get($channel, 'title', 'C'), 0, 1)) }}</span>@endif
                </span>
                <span class="channel-card-copy">
                    <strong>{{ data_get($channel, 'title', $isFa ? 'کانال پیشنهادی' : 'Suggested channel') }}</strong>
                    <small class="ltr">@{{ ltrim((string) data_get($channel, 'username', 'channel'), '@') }}</small>
                    <span class="channel-card-meta">
                        <span class="channel-card-members">
                            <x-icon name="users" />
                            <span class="number">{{ number_format((int) data_get($channel, 'members_count', 0)) }}</span>
                            {{ $isFa ? 'عضو' : 'members' }}
                        </span>
                        @if(data_get($channel, 'language'))<span class="channel-card-lang">{{ strtoupper((string) data_get($channel, 'language')) }}</span>@endif
                    </span>
                </span>
                <span class="channel-card-check" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5" />
                    </svg>
                </span>
            </label>
            @endforeach
        </div>
        @else
        <div class="notice"><x-icon name="channel" />
            <p>{{ $isFa ? 'هنوز کانال پیشنهادی فعالی ثبت نشده است؛ کانال‌ها را با سرچ بالا اضافه کنید.' : 'No active suggestions yet. Add channels via the search box above.' }}</p>
        </div>
        @endif

        <div class="field" style="margin-top:16px">
            <label class="field-label" for="manual-channels">{{ $isFa ? 'افزودن دستی (اختیاری)' : 'Manual entry (optional)' }}</label>
            <textarea class="textarea ltr" id="manual-channels" name="manual_channels" placeholder="@channel_one&#10;@channel_two">{{ old('manual_channels', data_get($draftRevision, 'manual_channels', $existingManualChannels)) }}</textarea>
            <p class="field-help">{{ $isFa ? 'در صورت نیاز، آیدی کانال‌های دیگر را در خطوط جدا وارد کنید. کانال باید عمومی و واجد شرایط باشد.' : 'Optionally add other usernames one per line. Channels must be public and eligible.' }}</p>
        </div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden data-budget-pane data-usd-to-irr="{{ $quote['usd_to_irr_rate'] ?? 0 }}" data-gram-to-usd="{{ $quote['gram_to_usd_rate'] ?? 0 }}" data-min-budget-toman="{{ (int) ($minimumOrderToman ?? 100000) }}">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'بودجه و پیشنهاد قیمت' : 'Budget & bid' }}</h2>
                <p class="card-subtitle">{{ $isFa ? 'پیشنهاد CPM و بودجه را بر اساس گرام وارد کنید؛ تعداد نمایش خودکار محاسبه می‌شود.' : 'Enter your CPM bid and budget in Gram; impressions are calculated automatically.' }}</p>
            </div><span class="chip">4</span>
        </div>
        <div class="form-grid">
            <div class="field">
                <span class="field-label required">{{ $isFa ? 'پلن انتخابی' : 'Delivery plan' }}</span>
                <div class="two-column">
                    <label class="option-card"><input type="radio" name="plan" value="standard" required @checked(old('plan', data_get($draftRevision, 'plan' , 'standard' ))==='standard' ) data-plan-option><span class="option-card-copy"><strong>{{ $isFa ? 'استاندارد' : 'Standard' }}</strong><small>{{ $isFa ? 'هزینه متعادل و ورود عادی به مزایده' : 'Balanced cost and standard auction priority' }}</small></span></label>
                    <label class="option-card"><input type="radio" name="plan" value="competitive" required @checked(old('plan', data_get($draftRevision, 'plan' , 'standard' ))==='competitive' ) data-plan-option data-plan-competitive><span class="option-card-copy"><strong>{{ $isFa ? 'رقابتی' : 'Competitive' }}</strong><small>{{ $isFa ? 'CPM بالاتر برای اولویت بیشتر' : 'Higher CPM for stronger priority' }}</small></span></label>
                </div>
                <p class="field-help" data-effective-cpm-note hidden></p>
            </div>
            <div class="field">
                <label class="field-label required" for="cpm-gram">{{ $isFa ? 'پیشنهاد CPM' : 'CPM bid' }}</label>
                <div class="input-with-suffix"><input class="input number" id="cpm-gram" name="cpm_gram" type="number" min="0.1" max="1000000" step="0.000000001" inputmode="decimal" required value="{{ old('cpm_gram', data_get($draftRevision, 'cpm_gram', 0.1)) }}" data-cpm-input><span>GRAM / 1K</span></div>
                <p class="field-help">{{ $isFa ? 'حداقل فعلی Telegram Ads برابر ۰.۱ گرام برای هر هزار نمایش است. در پلن رقابتی، اگر زیر ۱ باشد به ۱ تبدیل و اگر بالای ۱ باشد در ۱.۵ ضرب می‌شود.' : 'Telegram Ads minimum is 0.1 Gram per 1,000 impressions. In the competitive plan, CPM<1 becomes 1 and CPM>1 is multiplied by 1.5.' }}</p>
            </div>
            <div class="field">
                <label class="field-label required" for="media-budget-gram">{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</label>
                <div class="input-with-suffix"><input class="input number" id="media-budget-gram" name="media_budget_gram" type="number" min="0.001" step="0.000000001" inputmode="decimal" required @readonly($editing) value="{{ old('media_budget_gram', data_get($draftRevision, 'media_budget_gram', number_format((float) data_get($quoteData, 'media_budget_gram', 0), 9, '.', ''))) }}" data-budget-gram-input data-field-validator="media_budget_gram"><span>GRAM</span></div>
                <p class="field-help" data-budget-rial-line>{{ $isFa ? 'معادل ریالی: در حال محاسبه…' : 'Rial equivalent: calculating…' }}</p>
                <p class="field-error" data-inline-error-for="media_budget_gram" hidden></p>
                @error('media_budget_toman')<p class="field-error" data-server-error="media_budget_gram">{{ $message }}</p>@enderror
                <input type="hidden" name="media_budget_toman" min="10000" value="{{ old('media_budget_toman', data_get($quoteData, 'media_budget_toman', data_get($defaults ?? [], 'media_budget_toman', 100000))) }}" data-budget-toman-hidden>
            </div>
            <div class="field">
                <label class="field-label" for="impression-goal">{{ $isFa ? 'تعداد نمایش حدودی' : 'Estimated impressions' }}</label>
                <div class="input-with-suffix"><input class="input number" id="impression-goal" name="impression_goal" type="number" min="1000" max="1000000000" step="1" readonly data-impression-display value="{{ old('impression_goal', data_get($draftRevision, 'impression_goal', data_get($defaults ?? [], 'impression_goal', 10000))) }}"><span>{{ $isFa ? 'نمایش' : 'impressions' }}</span></div>
                <p class="field-help" data-impression-help>{{ $isFa ? 'این تعداد به‌صورت خودکار از تقسیم بودجه (گرم) بر پیشنهاد CPM به دست می‌آید.' : 'Automatically calculated as budget (GRAM) divided by CPM suggestion.' }}</p>
                <p class="field-help field-error" data-impression-warning hidden style="color: var(--ap-danger); font-weight: 600;">{{ $isFa ? 'حداقل تعداد نمایش باید ۱٬۰۰۰ باشد. بودجه را بیشتر یا CPM را کم کنید.' : 'Impression goal must be at least 1,000. Increase your budget or lower CPM.' }}</p>
            </div>
            <div class="field">
                <label class="field-label" for="planned-start">{{ $isFa ? 'زمان شروع پیشنهادی' : 'Preferred start time' }}</label>
                <input class="input number" id="planned-start" name="planned_start_at" type="datetime-local" value="{{ old('planned_start_at', data_get($draftRevision, 'planned_start_at')) }}">
                <p class="field-help">{{ $isFa ? 'شروع نهایی به زمان تأیید Telegram وابسته است.' : 'Final launch time depends on Telegram approval.' }}</p>
            </div>
        </div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ $isFa ? 'قیمت و قوانین' : 'Price and policies' }}</h2>
                <p class="card-subtitle">{{ $isFa ? 'قبل از پرداخت، جزئیات هزینه را بررسی کنید.' : 'Review the full breakdown before paying.' }}</p>
            </div><span class="chip">5</span>
        </div>
        <dl class="definition-list">
            <div class="definition-row">
                <dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt>
                <dd class="number">@if($isFa){{ number_format($mediaToman) }} تومان @else {{ $totalUsd ? '$'.number_format($totalUsd / (1 + $servicePercent / 100), 2) : 'Calculated after quote' }}@endif</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? "کارمزد خدمات ({$servicePercent}٪)" : "Service fee ({$servicePercent}%)" }}</dt>
                <dd class="number">@if($isFa){{ number_format($serviceToman) }} تومان @else{{ $totalUsd ? '$'.number_format($totalUsd - ($totalUsd / (1 + $servicePercent / 100)), 2) : '—' }}@endif</dd>
            </div>
            <div class="definition-row">
                <dt>{{ $isFa ? 'کارمزد درگاه' : 'Gateway fee' }}</dt>
                <dd class="number">@if($isFa){{ number_format($gatewayToman) }} تومان @else{{ $isFa ? '' : 'Shown after choosing a method' }}@endif</dd>
            </div>
            <div class="definition-row">
                <dt><strong>{{ __('ui.common.total') }}</strong></dt>
                <dd class="number"><strong>@if($isFa){{ number_format($totalToman) }} تومان @else{{ $totalUsd ? '$'.number_format($totalUsd, 2) : 'Calculated after quote' }}@endif</strong></dd>
            </div>
        </dl>
        <div class="notice notice-warning" style="margin-top:16px"><x-icon name="warning" />
            <p>{{ $isFa ? 'تصمیم نهایی با Telegram است. در صورت رد، بازپرداخت نقدی نداریم؛ پس از تطبیق، فقط مبلغ قطعی‌کسرنشده به اعتبار تبلیغاتیِ غیرقابل‌برداشت تبدیل می‌شود.' : 'Telegram makes the final decision. Rejected ads are not cash-refundable; after reconciliation, only funds not finally deducted become non-withdrawable ad credit.' }}</p>
        </div>
        <label class="checkbox checklist-accept" style="margin-top:14px"><input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted'))><span class="checklist-accept-text">{{ $isFa ? 'قوانین تبلیغات، شرایط پرداخت و سیاست رد Telegram و اعتبار تبلیغاتی را خواندم و می‌پذیرم.' : 'I have read and accept the advertising, payment, Telegram rejection, and ad-credit terms.' }}</span></label>
    </section>

    <div class="wizard-actions">
        <button class="btn btn-primary" type="button" data-wizard-next data-wizard-next-btn disabled>{{ __('ui.actions.continue') }}<x-icon name="arrow" /></button>
        <button class="btn btn-primary" type="submit" data-wizard-submit data-wizard-submit-btn hidden disabled>{{ $editing ? ($isFa ? 'ذخیره تغییرات' : 'Save changes') : ($isFa ? 'ثبت سفارش' : 'Create order') }}</button>
        <button class="btn btn-text" type="button" data-wizard-prev disabled>{{ __('ui.actions.back') }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const wizard = document.querySelector('[data-campaign-order-wizard]');
        if (!wizard) return;

        const root = document.documentElement;
        const visualViewport = window.visualViewport;
        const telegram = window.Telegram?.WebApp;
        const currentLabel = wizard.querySelector('[data-wizard-current]');
        const progress = wizard.querySelector('[data-wizard-progress]');
        const totalSteps = Math.max(1, Number(wizard.dataset.wizardTotalSteps || 6));

        // There are 5 form panes for a new campaign. Payment happens AFTER the
        // order is saved, so the user journey is 6 steps. Keep app.js wizard
        // behaviour unchanged while displaying progress against the real journey.
        const syncJourneyProgress = () => {
            if (!progress || !currentLabel) return;
            const step = Math.max(1, Number(currentLabel.textContent || 1));
            progress.style.setProperty('--progress', `${Math.min(100, (step / totalSteps) * 100)}%`);
        };

        if (currentLabel) {
            new MutationObserver(syncJourneyProgress).observe(currentLabel, {
                childList: true,
                characterData: true,
                subtree: true,
            });
        }
        wizard.addEventListener('click', () => queueMicrotask(syncJourneyProgress));
        window.addEventListener('pageshow', syncJourneyProgress, {
            passive: true
        });
        syncJourneyProgress();

        const isEditableField = (element) => {
            if (!(element instanceof HTMLElement) || !wizard.contains(element)) return false;
            if (element.matches('textarea, select, [contenteditable="true"]')) return true;
            if (!element.matches('input')) return false;
            return !['button', 'checkbox', 'file', 'hidden', 'radio', 'reset', 'submit']
                .includes((element.type || 'text').toLowerCase());
        };

        const telegramStableHeight = () => Number(telegram?.viewportStableHeight || 0);

        // Capture the stable LAYOUT viewport height before the keyboard opens.
        // Two different behaviours exist in Telegram/iOS/Android WebViews:
        //   1) iOS often keeps the layout viewport stable but pans the visual
        //      viewport (visualViewport.offsetTop grows).
        //   2) Some Android/WebView versions actually shrink window.innerHeight.
        // Compensating for those two signals directly avoids double-counting the
        // keyboard, which is what caused the previous action bar to jump to the
        // top of the screen in the user's iPhone screenshots.
        let stableLayoutHeight = Math.max(window.innerHeight, telegramStableHeight());
        let rafId = 0;

        const measureKeyboard = () => {
            rafId = 0;
            const editingField = isEditableField(document.activeElement);

            if (!editingField) {
                stableLayoutHeight = Math.max(stableLayoutHeight, window.innerHeight, telegramStableHeight());
            }

            const visualPan = editingField && visualViewport ?
                Math.max(0, Number(visualViewport.offsetTop || 0)) :
                0;
            const layoutShrink = editingField ?
                Math.max(0, stableLayoutHeight - window.innerHeight) :
                0;

            // Move the fixed chrome down by whichever mechanism actually moved it
            // up. Do NOT use (stableHeight - visualViewport.height): on iOS that
            // includes both keyboard shrink and pan and over-compensates badly.
            const rawShift = Math.max(visualPan, layoutShrink);
            const shift = rawShift >= 20 ? Math.round(rawShift) : 0;

            root.style.setProperty('--campaign-create-keyboard-shift', `${shift}px`);
            root.classList.toggle('campaign-create-keyboard-open', shift > 0);
        };

        const scheduleKeyboardMeasure = () => {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(measureKeyboard);
        };

        visualViewport?.addEventListener('resize', scheduleKeyboardMeasure, {
            passive: true
        });
        visualViewport?.addEventListener('scroll', scheduleKeyboardMeasure, {
            passive: true
        });
        window.addEventListener('resize', scheduleKeyboardMeasure, {
            passive: true
        });
        document.addEventListener('focusin', scheduleKeyboardMeasure, true);
        document.addEventListener('focusout', () => setTimeout(scheduleKeyboardMeasure, 60), true);
        telegram?.onEvent?.('viewportChanged', scheduleKeyboardMeasure);

        window.addEventListener('pagehide', () => {
            root.classList.remove('campaign-create-keyboard-open');
            root.style.removeProperty('--campaign-create-keyboard-shift');
        }, {
            once: true
        });

        scheduleKeyboardMeasure();
    })();
</script>
@endpush
