@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'احراز هویت' : 'Identity verification') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    // Controller-supplied $kycApplication takes priority; otherwise load
    // the latest KYC application via the HasOne relationship on the User
    // model. This is null-safe: when the user has never submitted KYC,
    // we just get null and the form below renders in "new submission" mode.
    $application = $kycApplication ?? $application ?? $currentUser?->latestKycApplication;
    $status = data_get($application, 'status', 'draft');
    $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $level = data_get($currentUser, 'kyc_level', 'base');
    $level = $level instanceof \BackedEnum ? $level->value : (string) $level;
    $isApproved = $status === 'approved' || $level === 'rial_verified';
    $isPending = in_array($status, ['submitted', 'under_review'], true);
    $needsCorrection = $status === 'changes_requested';
    $phoneVerified = (bool) data_get($currentUser, 'phone_verified_at');
    $cards = collect($fundingCards ?? data_get($application, 'cards', $currentUser?->fundingCards ?? []));
    $approvedCards = $cards->filter(fn ($card) => data_get($card, 'status') === 'approved');
@endphp

<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'امنیت پرداخت ریالی' : 'Rial payment security' }}</div><div class="cluster"><h1 class="page-title">{{ $isFa ? 'احراز هویت' : 'Identity verification' }}</h1><x-status-chip :value="$isApproved ? 'rial_verified' : $status" /></div><p class="page-lead">{{ $isFa ? 'این بررسی فقط برای پرداخت ریالی لازم است و از استفاده از کارت اشخاص دیگر جلوگیری می‌کند.' : 'This check is required only for rial payments and helps prevent third-party card use.' }}</p></div></header>

@if($isApproved)
    <div class="notice notice-success"><x-icon name="check" /><div><strong>{{ $isFa ? 'احراز هویت شما تأیید شده است' : 'Your identity is verified' }}</strong><p>{{ $isFa ? 'می‌توانید با کارت‌های تأییدشده پرداخت ریالی انجام دهید.' : 'You can make rial payments with your approved cards.' }}</p></div></div>
@elseif($isPending)
    <div class="notice"><x-icon name="clock" /><div><strong>{{ $isFa ? 'مدارک در صف بررسی است' : 'Your documents are under review' }}</strong><p>{{ $isFa ? 'نتیجه پس از تصمیم ادمین از طریق ربات اطلاع داده می‌شود. نیازی به ارسال دوباره نیست.' : 'The bot will notify you after an admin decision. Please do not resubmit.' }}</p></div></div>
@elseif($needsCorrection)
    <div class="notice notice-warning"><x-icon name="warning" /><div><strong>{{ $isFa ? 'اطلاعات نیازمند اصلاح است' : 'Your information needs correction' }}</strong><p>{{ data_get($application, 'admin_note') ?: ($isFa ? 'اطلاعات صاحب کارت باید با مدارک هویتی مطابقت داشته باشد. کارت متعلق به خودتان را ثبت کنید.' : 'The cardholder must match the identity documents. Submit a card that belongs to you.') }}</p></div></div>
@endif

<div class="two-column section" style="align-items:start">
    <aside class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مراحل بررسی' : 'Verification steps' }}</h2><p class="card-subtitle">{{ $isFa ? 'اطلاعات و دو تصویر واضح' : 'Identity details and two clear images' }}</p></div></div>
        <div class="kyc-steps">
            <div class="kyc-step {{ $phoneVerified ? 'is-complete' : 'is-current' }}"><span class="kyc-step-index">@if($phoneVerified)<x-icon name="check" size="sm" />@else 1 @endif</span><span>{{ $isFa ? 'تأیید شماره تلفن' : 'Verify phone number' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : ($phoneVerified ? 'is-current' : '') }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 2 @endif</span><span>{{ $isFa ? 'اطلاعات هویتی و کارت' : 'Identity and card details' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : '' }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 3 @endif</span><span>{{ $isFa ? 'تصویر کارت ملی' : 'National ID image' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : '' }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 4 @endif</span><span>{{ $isFa ? 'تصویر شخص همراه کارت' : 'Selfie holding the ID' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : '' }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 5 @endif</span><span>{{ $isFa ? 'تأیید ادمین' : 'Admin approval' }}</span></div>
        </div>
        <hr class="divider">
        <div class="notice"><x-icon name="lock" /><p>{{ $isFa ? 'هرگز CVV2، رمز کارت، تاریخ انقضا یا رمز پویا را درخواست نمی‌کنیم.' : 'We never request your CVV2, PIN, expiry date, or one-time password.' }}</p></div>
    </aside>

    <section class="card">
        @if($isApproved)
            <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'کارت‌های تأییدشده' : 'Approved cards' }}</h2><p class="card-subtitle">{{ $isFa ? 'پرداخت ZarinPay را فقط با یکی از این کارت‌ها انجام دهید.' : 'Use one of these cards for ZarinPay payments.' }}</p></div></div>
            @if($approvedCards->isEmpty())<p class="muted">{{ $isFa ? 'کارت تأییدشده‌ای برای نمایش وجود ندارد.' : 'No approved card is available.' }}</p>@else<div class="stack-sm">@foreach($approvedCards as $card)<div class="option-card"><span class="quick-icon"><x-icon name="card" /></span><span class="option-card-copy"><strong class="number ltr">•••• •••• •••• {{ data_get($card, 'last4', '—') }}</strong><small>{{ data_get($card, 'holder_name_search', $isFa ? 'صاحب حساب تأییدشده' : 'Verified account holder') }}</small></span><x-status-chip :value="data_get($card, 'status', 'approved')" /></div>@endforeach</div>@endif
        @elseif(!$isPending)
            <div class="card-head"><div><h2 class="card-title">{{ $needsCorrection ? ($isFa ? 'اصلاح اطلاعات' : 'Correct your details') : ($isFa ? 'ثبت اطلاعات' : 'Submit your details') }}</h2><p class="card-subtitle">{{ $isFa ? 'همه فیلدها باید متعلق به یک شخص باشند.' : 'Every field must belong to the same person.' }}</p></div></div>
            @if(!$phoneVerified)
                <div class="notice notice-warning"><x-icon name="warning" /><div><strong>{{ $isFa?'ابتدا شماره Telegram را تأیید کنید':'Verify your Telegram phone first' }}</strong><p>{{ $isFa?'برای جلوگیری از استفاده از شماره دیگران، فرم احراز هویت تا اشتراک شماره رسمی باز نمی‌شود.':'To prevent third-party phone use, the identity form stays locked until you share your number through Telegram’s official flow.' }}</p></div></div>
                <button class="btn btn-primary btn-block section" type="button" data-request-contact data-contact-status="#contact-share-status" data-success-message="{{ $isFa?'شماره ارسال شد؛ در حال به‌روزرسانی وضعیت…':'Number shared; refreshing status…' }}" data-unsupported-message="{{ $isFa?'به گفتگوی ربات برگردید و دکمه «تأیید شماره همراه» را بزنید.':'Return to the bot chat and tap its Verify phone number button.' }}"><x-icon name="user" />{{ $isFa?'اشتراک شماره با Telegram':'Share phone via Telegram' }}</button>
                <p class="field-help" id="contact-share-status" data-contact-status hidden style="margin-top:10px"></p>
            @else
            <form class="form-grid" action="{{ $safeRoute('app.identity.store') }}" method="post" enctype="multipart/form-data" data-loading-form data-telegram-auth>
                @csrf
                <div class="field-row">
                    <div class="field"><label class="field-label" for="phone">{{ $isFa ? 'شماره تلفن تأییدشده' : 'Verified phone number' }}</label><input class="input ltr" id="phone" type="tel" readonly value="{{ data_get($currentUser, 'phone') }}"><p class="field-help">{{ $isFa ? 'برای تغییر شماره، ابتدا آن را در Telegram به‌روز کنید و با پشتیبانی تماس بگیرید.' : 'To change it, update your number in Telegram and contact support.' }}</p></div>
                    <div class="field"><label class="field-label required" for="legal-name">{{ $isFa ? 'نام و نام خانوادگی صاحب حساب' : 'Account holder’s legal name' }}</label><input class="input" id="legal-name" name="legal_name" autocomplete="name" required value="{{ old('legal_name', data_get($application, 'legal_name_encrypted')) }}"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label class="field-label required" for="card-holder-name">{{ $isFa ? 'نام صاحب کارت بانکی' : 'Bank card holder name' }}</label><input class="input" id="card-holder-name" name="card_holder_name" autocomplete="cc-name" required value="{{ old('card_holder_name', data_get($application, 'legal_name_encrypted')) }}"><p class="field-help">{{ $isFa ? 'دقیقاً همان نامی که روی کارت بانکی نوشته شده است. در صورت مغایرت با کد ملی، حساب در سطح پایه می‌ماند تا تصحیح کنید.' : 'Exactly as printed on the bank card. If it doesn’t match the national ID, your account stays at base level until corrected.' }}</p></div>
                    <div class="field"><label class="field-label required" for="national-id">{{ $isFa ? 'کد ملی' : 'National ID number' }}</label><input class="input number ltr" id="national-id" name="national_id" inputmode="numeric" pattern="[0-9۰-۹]{10}" maxlength="10" required value="{{ old('national_id', data_get($application, 'national_id_encrypted')) }}"></div>
                </div>
                <div class="field">
                    <label class="field-label required" for="card-number">{{ $isFa ? 'شماره کارت بانکی که می‌خواهید با آن واریز کنید' : 'Bank card number you want to deposit with' }}</label>
                    <input class="input number ltr" id="card-number" name="card_number" inputmode="numeric" autocomplete="cc-number" pattern="[0-9۰-۹ ]{16,19}" maxlength="19" required value="{{ old('card_number') }}" placeholder="0000 0000 0000 0000">
                    <p class="field-help">{{ $isFa ? 'کارت باید متعلق به همان کد ملی و همان نام صاحب حساب باشد. در غیر این صورت، درخواست بدون ورود به صف بررسی نگه داشته می‌شود.' : 'The card must belong to the same national ID and card holder name. Otherwise the request is held back without entering the review queue.' }}</p>
                </div>
                <div class="two-column">
                    <div class="field"><span class="field-label {{ $needsCorrection ? '' : 'required' }}">{{ $isFa ? 'تصویر کارت ملی (خالی)' : 'National ID image (clean)' }}</span><label class="upload-box" for="national-card-image"><input id="national-card-image" name="national_id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" @required(!$needsCorrection) data-preview-input="#national-card-preview"><span class="upload-box-content"><span class="quick-icon"><x-icon name="upload" /></span><strong>{{ $needsCorrection ? ($isFa?'تعویض در صورت نیاز':'Replace only if needed') : ($isFa ? 'انتخاب یا گرفتن عکس' : 'Choose or take a photo') }}</strong><small class="muted">{{ $isFa ? 'چهار گوشه کارت و نوشته‌ها واضح باشد؛ کارت ملی بدون پوشش و خالی.' : 'Show all four corners with readable text; the national ID must be clean and unobstructed.' }}</small></span><img class="upload-preview" id="national-card-preview" alt=""></label></div>
                    <div class="field"><span class="field-label {{ $needsCorrection ? '' : 'required' }}">{{ $isFa ? 'تصویر شخص همراه کارت ملی' : 'Selfie holding the ID' }}</span><label class="upload-box" for="selfie-image"><input id="selfie-image" name="selfie_with_id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="user" @required(!$needsCorrection) data-preview-input="#selfie-preview"><span class="upload-box-content"><span class="quick-icon"><x-icon name="upload" /></span><strong>{{ $needsCorrection ? ($isFa?'تعویض در صورت نیاز':'Replace only if needed') : ($isFa ? 'انتخاب یا گرفتن عکس' : 'Choose or take a photo') }}</strong><small class="muted">{{ $isFa ? 'چهره و کارت هر دو واضح و بدون بازتاب باشند.' : 'Keep both your face and ID clear and glare-free.' }}</small></span><img class="upload-preview" id="selfie-preview" alt=""></label></div>
                </div>
                <label class="checkbox"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>{{ $isFa ? 'صحت اطلاعات را تأیید می‌کنم و با بررسی مدارک برای فعال‌سازی پرداخت ریالی موافقم. می‌دانم که احراز سریع معمولاً ۱ ساعت و نهایتاً ۲۴ ساعت طول می‌کشد.' : 'I confirm the information is accurate and consent to document review for rial payments. I understand fast verification usually takes 1 hour, at most 24 hours.' }}</span></label>
                <button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ارسال برای بررسی' : 'Submit for review' }}</button>
            </form>
            @endif
        @endif
    </section>
</div>
@endsection
