@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'ثبت تبلیغ' : 'Create campaign') . ' — ' . __('ui.brand'))

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
    $zarinPayAvailable = (bool) ($zarinPayEnabled ?? config('services.zarinpay.enabled', false));
    $nowPaymentsAvailable = (bool) ($nowPaymentsEnabled ?? config('services.nowpayments.enabled', false));
    $defaultFundingMode = $zarinPayAvailable ? 'zarinpay' : ($nowPaymentsAvailable ? 'nowpayments' : 'wallet');
@endphp

<header class="page-header">
    <div><div class="eyebrow">{{ $isFa ? 'ساخت مرحله‌به‌مرحله' : 'Guided setup' }}</div><h1 class="page-title">{{ $isFa ? 'ثبت تبلیغ جدید' : 'Create a campaign' }}</h1><p class="page-lead">{{ $isFa ? 'اطلاعات را قدم‌به‌قدم وارد کنید؛ پیش‌نویس شما تا زمان ثبت نهایی محفوظ می‌ماند.' : 'Add the details one step at a time. Your draft stays safe until submission.' }}</p></div>
</header>

<form action="{{ $editing ? ($draftId ? $safeRoute('app.campaigns.update', ['campaign' => $draftId]) : '#') : $safeRoute('app.campaigns.store') }}" method="post" class="wizard-shell" data-wizard data-loading-form data-telegram-auth>
    @csrf
    @if($editing) @method('PUT') @endif
    <div class="wizard-progress" aria-label="{{ $isFa ? 'پیشرفت ثبت کمپین' : 'Campaign setup progress' }}">
        <div class="wizard-progress-meta"><span>{{ $isFa ? 'مرحله' : 'Step' }} <b data-wizard-current>1</b> {{ $isFa ? 'از' : 'of' }} 6</span><span>{{ $isFa ? 'ذخیره خودکار پیش‌نویس' : 'Draft autosave ready' }}</span></div>
        <div class="progress"><span data-wizard-progress style="--progress:16.6%"></span></div>
    </div>

    <section class="wizard-pane card" data-wizard-step>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اطلاعات پایه' : 'Campaign basics' }}</h2><p class="card-subtitle">{{ $isFa ? 'این عنوان فقط برای مدیریت خودتان است.' : 'The internal title is visible only to you.' }}</p></div><span class="chip">1</span></div>
        <div class="form-grid">
            <div class="field"><label class="field-label required" for="internal-title">{{ $isFa ? 'عنوان تبلیغ' : 'Internal title' }}</label><input class="input" id="internal-title" name="internal_title" required maxlength="100" value="{{ old('internal_title', data_get($draftRevision, 'internal_title')) }}" placeholder="{{ $isFa ? 'مثلاً جذب عضو برای کانال فروشگاه' : 'e.g. Grow our store channel' }}"><p class="field-help">{{ $isFa ? 'مخاطبان این عنوان را نمی‌بینند.' : 'Your audience will not see this title.' }}</p></div>
            <div class="field-row">
                <div class="field"><label class="field-label required" for="destination-type">{{ $isFa ? 'نوع مقصد' : 'Destination type' }}</label><select class="select" id="destination-type" name="destination_type" required><option value="">{{ $isFa ? 'انتخاب کنید' : 'Choose one' }}</option>@foreach(['channel' => ($isFa ? 'کانال' : 'Channel'), 'bot' => ($isFa ? 'ربات' : 'Bot'), 'group' => ($isFa ? 'گروه عمومی' : 'Public group'), 'website' => ($isFa ? 'وب‌سایت' : 'Website')] as $value => $label)<option value="{{ $value }}" @selected(old('destination_type', data_get($draftRevision, 'destination_type')) === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="field"><label class="field-label required" for="campaign-language">{{ $isFa ? 'زبان تبلیغ' : 'Ad language' }}</label><select class="select" id="campaign-language" name="language" required><option value="fa" @selected(old('language', data_get($draftRevision, 'language', $isFa ? 'fa' : 'en')) === 'fa')>فارسی</option><option value="en" @selected(old('language', data_get($draftRevision, 'language', $isFa ? 'fa' : 'en')) === 'en')>English</option></select></div>
            </div>
            <div class="field"><label class="field-label required" for="destination-url">{{ $isFa ? 'لینک مقصد' : 'Destination link' }}</label><input class="input ltr" id="destination-url" name="destination_url" type="url" required value="{{ old('destination_url', data_get($draftRevision, 'destination_url')) }}" placeholder="https://t.me/your_channel"><p class="field-help">{{ $isFa ? 'لینک کانال، ربات یا گروه عمومی را دقیق وارد کنید.' : 'Enter the exact public channel, bot, or group link.' }}</p></div>
            <div class="field"><label class="field-label required" for="placement-type">{{ $isFa?'محل نمایش تبلیغ':'Ad placement' }}</label><select class="select" id="placement-type" name="placement_type" required>@foreach(['channel_posts'=>($isFa?'کانال‌ها':'Channels'),'search_results'=>($isFa?'نتایج جستجو':'Search results'),'bot_messages'=>($isFa?'ربات‌ها':'Bots'),'broad'=>($isFa?'توزیع گسترده':'Broad delivery')] as $value=>$label)<option value="{{ $value }}" @selected(old('placement_type',data_get($draftRevision,'placement_type','channel_posts'))===$value)>{{ $label }}</option>@endforeach</select><p class="field-help">{{ $isFa?'امکان و جزئیات اجرای نهایی پس از بررسی اپراتور و Telegram تأیید می‌شود.':'Final availability and delivery details are confirmed after operator and Telegram review.' }}</p></div>
        </div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'متن تبلیغ' : 'Ad copy' }}</h2><p class="card-subtitle">{{ $isFa ? 'متن اصلی که کاربر در Telegram می‌بیند.' : 'The message people will see in Telegram.' }}</p></div><span class="chip">2</span></div>
        <div class="two-column">
            <div class="form-grid">
                <div class="field"><label class="field-label required" for="ad-text">{{ $isFa ? 'متن تبلیغ' : 'Ad text' }}</label><textarea class="textarea" id="ad-text" name="ad_text" required maxlength="160" data-count-target="#ad-text-counter" data-preview-target="#ad-preview-text" placeholder="{{ $isFa ? 'شفاف، کوتاه و دقیق بنویسید.' : 'Write a clear, concise message.' }}">{{ old('ad_text', data_get($draftRevision, 'ad_text')) }}</textarea><div class="counter number" id="ad-text-counter">0 / 160</div><p class="field-help">{{ $isFa ? 'شکست خط، فهرست شماره‌دار، لینک اضافی و استفاده افراطی از ایموجی مجاز نیست.' : 'Avoid line breaks, lists, extra links, excessive emoji, or stylized capitalization.' }}</p></div>
                <div class="notice"><x-icon name="check" /><p>{{ $isFa ? 'متن و مقصد باید یک موضوع و زبان مشترک داشته باشند.' : 'The ad copy and destination must match in topic and language.' }}</p></div>
            </div>
            <div>
                <p class="field-label">{{ $isFa ? 'پیش‌نمایش' : 'Preview' }}</p>
                <div class="ad-preview"><div class="ad-preview-head"><span class="avatar"><x-icon name="channel" /></span><div><strong>{{ $isFa ? 'پیام تبلیغاتی' : 'Sponsored message' }}</strong><div class="subtle" style="font-size:11px">{{ $isFa ? 'پیش‌نمایش تقریبی' : 'Approximate preview' }}</div></div></div><div class="ad-preview-body"><p class="ad-preview-text" id="ad-preview-text" data-placeholder="{{ $isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.' }}">{{ old('ad_text', data_get($draftRevision, 'ad_text', $isFa ? 'متن تبلیغ شما اینجا نمایش داده می‌شود.' : 'Your ad copy will appear here.')) }}</p></div><div class="ad-preview-link">{{ $isFa ? 'مشاهده' : 'View' }}</div></div>
            </div>
        </div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden data-channel-picker>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'کانال‌های هدف' : 'Target channels' }}</h2><p class="card-subtitle">{{ $isFa ? 'از پیشنهادها انتخاب کنید یا آیدی کانال‌های عمومی را وارد کنید.' : 'Choose suggestions or add public channels manually.' }}</p></div><span class="chip">3</span></div>
        @if($categoryItems->isNotEmpty())
            <div class="category-tabs" role="group" aria-label="{{ $isFa ? 'دسته‌بندی کانال‌ها' : 'Channel categories' }}"><button class="category-tab is-active" type="button" data-category-filter="all" aria-pressed="true">{{ __('ui.common.all') }}</button>@foreach($categoryItems as $category)@php($slug = (string) data_get($category, 'slug', data_get($category, 'id'))) <button class="category-tab" type="button" data-category-filter="{{ $slug }}" aria-pressed="false">{{ $isFa ? data_get($category, 'title_fa', data_get($category, 'title_en')) : data_get($category, 'title_en', data_get($category, 'title_fa')) }} <span class="number">{{ collect(data_get($category, 'channels', []))->count() }}/30</span></button>@endforeach</div>
        @endif
        @if(count($channelRows))
            <div class="channel-list" style="margin-top:10px">@foreach($channelRows as $row)@php($channel = $row['channel'])<label class="channel-row" data-channel-category="{{ implode(',', array_unique($row['categories'])) }}"><input type="checkbox" name="target_channel_ids[]" value="{{ data_get($channel, 'id', data_get($channel, 'username')) }}" @checked(in_array((string) data_get($channel, 'id', data_get($channel, 'username')), array_map('strval', old('target_channel_ids', $selectedTargetIds)), true))><span class="avatar">@if(data_get($channel, 'avatar_url'))<img src="{{ data_get($channel, 'avatar_url') }}" alt="">@else{{ mb_strtoupper(mb_substr((string) data_get($channel, 'title', 'C'), 0, 1)) }}@endif</span><span class="channel-copy"><strong>{{ data_get($channel, 'title', $isFa ? 'کانال پیشنهادی' : 'Suggested channel') }}</strong><small class="ltr">{{ '@'.ltrim((string) data_get($channel, 'username', 'channel'), '@') }} · {{ number_format((int) data_get($channel, 'members_count', 0)) }} {{ $isFa ? 'عضو' : 'members' }}</small></span>@if(data_get($channel, 'is_featured'))<span class="status-chip status-info">{{ $isFa ? 'پیشنهادی' : 'Featured' }}</span>@endif</label>@endforeach</div>
        @else
            <div class="notice"><x-icon name="channel" /><p>{{ $isFa ? 'هنوز کانال پیشنهادی فعالی ثبت نشده است؛ کانال‌ها را دستی اضافه کنید.' : 'There are no active suggestions yet. Add channels manually below.' }}</p></div>
        @endif
        <div class="field" style="margin-top:16px"><label class="field-label" for="manual-channels">{{ $isFa ? 'کانال‌های دیگر' : 'Other channels' }}</label><textarea class="textarea ltr" id="manual-channels" name="manual_channels" placeholder="@channel_one&#10;@channel_two">{{ old('manual_channels', data_get($draftRevision, 'manual_channels', $existingManualChannels)) }}</textarea><p class="field-help">{{ $isFa ? 'هر آیدی را در یک خط وارد کنید. کانال باید عمومی و واجد شرایط باشد.' : 'Enter one username per line. Channels must be public and eligible.' }}</p></div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'نحوه اجرا' : 'Delivery settings' }}</h2><p class="card-subtitle">{{ $isFa ? 'هدف نمایش، تکرار و زمان شروع را تنظیم کنید.' : 'Set the impression goal, frequency, and start time.' }}</p></div><span class="chip">4</span></div>
        <div class="form-grid">
            <div class="field-row"><div class="field"><label class="field-label required" for="impression-goal">{{ $isFa ? 'تعداد نمایش' : 'Impression goal' }}</label><input class="input number" id="impression-goal" name="impression_goal" type="number" min="1000" step="1000" required value="{{ old('impression_goal', data_get($draftRevision, 'impression_goal', data_get($defaults ?? [], 'impression_goal', 10000))) }}"><p class="field-help">{{ $isFa ? 'هزینه تبلیغ بر اساس تعداد نمایش محاسبه می‌شود.' : 'Campaign cost is calculated from impressions.' }}</p></div><div class="field"><label class="field-label required" for="frequency-cap">{{ $isFa ? 'نمایش برای هر نفر' : 'Frequency per user' }}</label><select class="select" id="frequency-cap" name="frequency_cap" required>@for($i=1;$i<=5;$i++)<option value="{{ $i }}" @selected((int) old('frequency_cap', data_get($draftRevision, 'frequency_cap', 2)) === $i)>{{ $i }} {{ $isFa ? 'بار در روز' : ($i === 1 ? 'time per day' : 'times per day') }}</option>@endfor</select></div></div>
            <div class="field"><label class="field-label required" for="media-budget">{{ $isFa?'بودجه رسانه':'Media budget' }}</label><div class="input-with-suffix"><input class="input number" id="media-budget" name="media_budget_toman" type="number" min="10000" max="10000000000" step="1000" required @readonly($editing) value="{{ old('media_budget_toman',data_get($quoteData,'media_budget_toman',data_get($defaults ?? [],'media_budget_toman',100000))) }}"><span>{{ $isFa?'تومان':'Toman' }}</span></div><p class="field-help">{{ $editing ? ($isFa?'بودجه سفارش پرداخت‌شده در مرحله اصلاح قابل تغییر نیست.':'A paid order’s budget cannot change during correction.') : ($isFa?'کارمزد خدمات و درگاه جداگانه و پیش از پرداخت نشان داده می‌شود.':'Service and gateway fees are itemized before payment.') }}</p></div>
            <div class="field"><label class="field-label" for="planned-start">{{ $isFa ? 'زمان شروع پیشنهادی' : 'Preferred start time' }}</label><input class="input number" id="planned-start" name="planned_start_at" type="datetime-local" value="{{ old('planned_start_at', data_get($draftRevision, 'planned_start_at')) }}"><p class="field-help">{{ $isFa ? 'شروع نهایی به زمان تأیید Telegram وابسته است.' : 'Final launch time depends on Telegram approval.' }}</p></div>
            <div class="field"><span class="field-label required">{{ $isFa ? 'پلن انتخابی' : 'Delivery plan' }}</span><div class="two-column"><label class="option-card"><input type="radio" name="plan" value="standard" required @checked(old('plan', data_get($draftRevision, 'plan', 'standard')) === 'standard')><span class="option-card-copy"><strong>{{ $isFa ? 'استاندارد' : 'Standard' }}</strong><small>{{ $isFa ? 'هزینه متعادل و ورود عادی به مزایده' : 'Balanced cost and standard auction priority' }}</small></span></label><label class="option-card"><input type="radio" name="plan" value="competitive" required @checked(old('plan', data_get($draftRevision, 'plan', 'standard')) === 'competitive')><span class="option-card-copy"><strong>{{ $isFa ? 'رقابتی' : 'Competitive' }}</strong><small>{{ $isFa ? 'CPM بالاتر برای اولویت بیشتر' : 'Higher CPM for stronger priority' }}</small></span></label></div></div>
            <div class="field"><label class="field-label required" for="cpm-gram">{{ $isFa ? 'پیشنهاد CPM' : 'CPM bid' }}</label><div class="input-with-suffix"><input class="input number" id="cpm-gram" name="cpm_gram" type="number" min="0.1" max="1000000" step="0.000000001" required value="{{ old('cpm_gram', data_get($draftRevision, 'cpm_gram', 0.1)) }}"><span>GRAM / 1K</span></div><p class="field-help">{{ $isFa ? 'حداقل فعلی Telegram Ads برابر ۰٫۱ گرام برای هر هزار نمایش است؛ پیشنهاد بالاتر می‌تواند اولویت مزایده را بیشتر کند و نتیجه را تضمین نمی‌کند.' : 'Telegram Ads currently lists a 0.1 Gram minimum per 1,000 impressions. A higher bid may improve auction priority but does not guarantee results.' }}</p></div>
        </div>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'قیمت و قوانین' : 'Price and policies' }}</h2><p class="card-subtitle">{{ $isFa ? 'قبل از پرداخت، جزئیات هزینه را بررسی کنید.' : 'Review the full breakdown before paying.' }}</p></div><span class="chip">5</span></div>
        <dl class="definition-list"><div class="definition-row"><dt>{{ $isFa ? 'بودجه رسانه' : 'Media budget' }}</dt><dd class="number">@if($isFa){{ number_format($mediaToman) }} تومان @else {{ $totalUsd ? '$'.number_format($totalUsd / (1 + $servicePercent / 100), 2) : 'Calculated after quote' }}@endif</dd></div><div class="definition-row"><dt>{{ $isFa ? "کارمزد خدمات ({$servicePercent}٪)" : "Service fee ({$servicePercent}%)" }}</dt><dd class="number">@if($isFa){{ number_format($serviceToman) }} تومان @else{{ $totalUsd ? '$'.number_format($totalUsd - ($totalUsd / (1 + $servicePercent / 100)), 2) : '—' }}@endif</dd></div><div class="definition-row"><dt>{{ $isFa ? 'کارمزد درگاه' : 'Gateway fee' }}</dt><dd class="number">@if($isFa){{ number_format($gatewayToman) }} تومان @else{{ $isFa ? '' : 'Shown after choosing a method' }}@endif</dd></div><div class="definition-row"><dt><strong>{{ __('ui.common.total') }}</strong></dt><dd class="number"><strong>@if($isFa){{ number_format($totalToman) }} تومان @else{{ $totalUsd ? '$'.number_format($totalUsd, 2) : 'Calculated after quote' }}@endif</strong></dd></div></dl>
        <div class="notice notice-warning" style="margin-top:16px"><x-icon name="warning" /><p>{{ $isFa ? 'تصمیم نهایی با Telegram است. در صورت رد، بازپرداخت نقدی نداریم؛ پس از تطبیق، فقط مبلغ قطعی‌کسرنشده به اعتبار تبلیغاتیِ غیرقابل‌برداشت تبدیل می‌شود.' : 'Telegram makes the final decision. Rejected ads are not cash-refundable; after reconciliation, only funds not finally deducted become non-withdrawable ad credit.' }}</p></div>
        <label class="checkbox" style="margin-top:12px"><input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted'))><span>{{ $isFa ? 'قوانین تبلیغات، شرایط پرداخت و سیاست رد Telegram و اعتبار تبلیغاتی را خواندم و می‌پذیرم.' : 'I have read and accept the advertising, payment, Telegram rejection, and ad-credit terms.' }}</span></label>
    </section>

    <section class="wizard-pane card" data-wizard-step hidden>
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'روش پرداخت' : 'Payment method' }}</h2><p class="card-subtitle">{{ $isFa ? 'می‌توانید مستقیم پرداخت کنید؛ شارژ قبلی کیف پول اجباری نیست.' : 'Pay directly or use your wallet. A wallet top-up is not required.' }}</p></div><span class="chip">6</span></div>
        <div class="form-grid">
            <label class="option-card"><input type="radio" name="funding_mode" value="wallet" required @checked(old('funding_mode',$defaultFundingMode) === 'wallet')><span class="quick-icon"><x-icon name="wallet" /></span><span class="option-card-copy"><strong>{{ $isFa ? 'کیف پول' : 'Wallet balance' }}</strong><small>{{ $isFa ? 'کسر فوری از موجودی قابل‌استفاده' : 'Use available funds immediately' }}</small></span></label>
            @if($zarinPayAvailable)<label class="option-card"><input type="radio" name="funding_mode" value="zarinpay" required @checked(old('funding_mode',$defaultFundingMode) === 'zarinpay')><span class="quick-icon"><x-icon name="card" /></span><span class="option-card-copy"><strong>ZarinPay</strong><small>{{ $isFa ? 'پرداخت مستقیم ریالی؛ نیازمند احراز هویت' : 'Direct rial payment; identity verification required' }}</small></span></label>@endif
            @if($nowPaymentsAvailable)<label class="option-card"><input type="radio" name="funding_mode" value="nowpayments" required @checked(old('funding_mode',$defaultFundingMode) === 'nowpayments')><span class="quick-icon"><x-icon name="globe" /></span><span class="option-card-copy"><strong>NOWPayments</strong><small>{{ $isFa ? 'پرداخت مستقیم رمزارزی' : 'Direct crypto payment' }}</small></span></label>@endif
        </div>
    </section>

    <div class="wizard-actions"><button class="btn btn-primary" type="button" data-wizard-next>{{ __('ui.actions.continue') }}<x-icon name="arrow" /></button><button class="btn btn-primary" type="submit" data-wizard-submit hidden>{{ $editing ? ($isFa ? 'ذخیره تغییرات' : 'Save changes') : ($isFa ? 'ثبت سفارش و ادامه پرداخت' : 'Create order and pay') }}</button><button class="btn btn-text" type="button" data-wizard-prev disabled>{{ __('ui.actions.back') }}</button></div>
</form>
@endsection
