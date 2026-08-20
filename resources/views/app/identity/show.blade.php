@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'احراز هویت' : 'Identity verification') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    // Defensive: when rendered outside an HTTP request lifecycle (CLI
    // test scripts, queue jobs, mailables), the $errors shared variable
    // is not bound by ShareErrorsFromSession. Default to an empty
    // ViewErrorBag so $errors->any() / $errors->has() don't throw
    // "Undefined variable $errors" / "Call to undefined method
    // MessageBag::getBag()" in those contexts.
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag();
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

    {{-- Verified identity details (legal name + masked national ID + approval date).
        Shown ONLY when the KYC application is approved. The legal_name_encrypted
        and national_id_encrypted attributes are auto-decrypted by the model's
        casts(), so by the time they reach the view they're plain strings.
        National ID is masked to show only the first 3 and last 3 digits —
        enough for the user to recognize it, not enough to be sensitive if
        someone glances at their screen. --}}
    @php
        $legalName = data_get($application, 'legal_name_encrypted');
        $nationalIdRaw = (string) data_get($application, 'national_id_encrypted', '');
        $nationalIdMasked = '';
        if ($nationalIdRaw !== '' && strlen($nationalIdRaw) >= 6) {
            $nationalIdMasked = mb_substr($nationalIdRaw, 0, 3).'******'.mb_substr($nationalIdRaw, -3);
        } elseif ($nationalIdRaw !== '') {
            $nationalIdMasked = str_repeat('*', strlen($nationalIdRaw));
        }
        $approvedAt = data_get($application, 'reviewed_at');
        $approvedAtFormatted = $approvedAt
            ? \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($approvedAt))
            : '—';
    @endphp
    <section class="section card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اطلاعات هویتی تأییدشده' : 'Verified identity details' }}</h2><p class="card-subtitle">{{ $isFa ? 'این اطلاعات پس از تأیید ادمین در پروفایل شما ثبت شده است.' : 'This information is recorded in your profile after admin approval.' }}</p></div><x-icon name="identity" /></div>
        <dl class="definition-list">
            <div class="definition-row"><dt>{{ $isFa ? 'نام و نام خانوادگی قانونی' : 'Legal name' }}</dt><dd>{{ $legalName ?: '—' }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'کد ملی' : 'National ID' }}</dt><dd class="number ltr">{{ $nationalIdMasked ?: '—' }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'شماره تلفن تأییدشده' : 'Verified phone' }}</dt><dd class="number ltr">{{ data_get($currentUser, 'phone', '—') }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'تاریخ تأیید' : 'Approved at' }}</dt><dd class="number">{{ $approvedAtFormatted }}</dd></div>
        </dl>
    </section>
@elseif($isPending)
    <div class="notice"><x-icon name="clock" /><div><strong>{{ $isFa ? 'مدارک در صف بررسی است' : 'Your documents are under review' }}</strong><p>{{ $isFa ? 'نتیجه پس از تصمیم ادمین از طریق ربات اطلاع داده می‌شود. نیازی به ارسال دوباره نیست.' : 'The bot will notify you after an admin decision. Please do not resubmit.' }}</p></div></div>
@elseif($needsCorrection)
    <div class="notice notice-warning"><x-icon name="warning" /><div><strong>{{ $isFa ? 'اطلاعات نیازمند اصلاح است' : 'Your information needs correction' }}</strong><p>{{ data_get($application, 'admin_note') ?: ($isFa ? 'اطلاعات صاحب کارت باید با مدارک هویتی مطابقت داشته باشد. کارت متعلق به خودتان را ثبت کنید.' : 'The cardholder must match the identity documents. Submit a card that belongs to you.') }}</p></div></div>
@endif

<div class="two-column section" style="align-items:start">
    <aside class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'مراحل بررسی' : 'Verification steps' }}</h2><p class="card-subtitle">{{ $isFa ? 'از اشتراک شماره تا تأیید نهایی ادمین' : 'From phone share to final admin approval' }}</p></div></div>
        <div class="kyc-steps">
            <div class="kyc-step {{ $phoneVerified ? 'is-complete' : 'is-current' }}"><span class="kyc-step-index">@if($phoneVerified)<x-icon name="check" size="sm" />@else 1 @endif</span><span>{{ $isFa ? 'اشتراک‌گذاری شماره تلفن' : 'Share phone number' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : ($phoneVerified ? 'is-current' : '') }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 2 @endif</span><span>{{ $isFa ? 'اطلاعات هویتی و بانکی' : 'Identity and bank info' }}</span></div>
            <div class="kyc-step {{ $isApproved ? 'is-complete' : '' }}"><span class="kyc-step-index">@if($isApproved)<x-icon name="check" size="sm" />@else 3 @endif</span><span>{{ $isFa ? 'تأیید ادمین و ارتقا سطح' : 'Admin approval and level upgrade' }}</span></div>
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
            <form class="form-grid" action="{{ $safeRoute('app.identity.store') }}" method="post" enctype="multipart/form-data" data-loading-form data-telegram-auth data-kyc-form>
                @csrf
                {{-- ─── Server-side error summary ─────────────────────────────────
                    Renders ONLY when the form comes back from the server with
                    validation errors. Lists each invalid field's error
                    message in a single compact notice (no duplication).
                    Individual fields below also get the .is-invalid class
                    via the @@error() directive so the user sees BOTH the
                    summary AND the per-field red highlight. --}}
                @error('kyc')<div class="notice notice-danger" role="alert" style="margin-bottom:10px"><x-icon name="warning" /><div><strong>{{ $isFa ? 'خطا در ثبت درخواست' : 'Submission error' }}</strong><p style="margin:4px 0 0 0">{{ $message }}</p></div></div>@enderror
                @error('phone')<div class="notice notice-warning" role="alert" style="margin-bottom:10px"><x-icon name="warning" /><div><strong>{{ $isFa ? 'شماره تلفن' : 'Phone' }}</strong><p style="margin:4px 0 0 0">{{ $message }}</p></div></div>@enderror
                @if($errors->any() && !$errors->has('kyc') && !$errors->has('phone'))
                    <div class="notice notice-danger" role="alert" style="margin-bottom:10px">
                        <x-icon name="warning" />
                        <div><strong>{{ $isFa ? 'لطفاً موارد زیر را اصلاح کنید:' : 'Please fix the following:' }}</strong>
                            <ul>
                                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                @endif
                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="phone">{{ $isFa ? 'شماره تلفن تأییدشده' : 'Verified phone number' }}</label>
                        <input class="input ltr" id="phone" type="tel" readonly value="{{ data_get($currentUser, 'phone') }}">
                        <p class="field-help">{{ $isFa ? 'برای تغییر شماره، ابتدا آن را در Telegram به‌روز کنید و با پشتیبانی تماس بگیرید.' : 'To change it, update your number in Telegram and contact support.' }}</p>
                    </div>
                    <div class="field" data-kyc-field="legal_name">
                        <label class="field-label required" for="legal-name">{{ $isFa ? 'نام و نام خانوادگی صاحب حساب' : 'Account holder’s legal name' }}</label>
                        <input class="input" id="legal-name" name="legal_name" autocomplete="name" required value="{{ old('legal_name', data_get($application, 'legal_name_encrypted')) }}" minlength="3" maxlength="120">
                        <p class="field-help">{{ $isFa ? 'دقیقاً همان نامی که روی کارت بانکی نوشته شده است وارد کنید.' : 'Enter exactly the name printed on your bank card.' }}</p>
                        @error('legal_name')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="field-row">
                    <div class="field" data-kyc-field="national_id">
                        <label class="field-label required" for="national-id">{{ $isFa ? 'کد ملی' : 'National ID number' }}</label>
                        <input class="input number ltr" id="national-id" name="national_id" inputmode="numeric" pattern="[0-9۰-۹]{10}" maxlength="10" required value="{{ old('national_id', data_get($application, 'national_id_encrypted')) }}" data-iranian-national-id>
                        <p class="field-help">{{ $isFa ? 'کد ملی ۱۰ رقمی خود را وارد کنید.' : 'Enter your 10-digit national ID.' }}</p>
                        @error('national_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field" data-kyc-field="card_number">
                        <label class="field-label required" for="card-number">{{ $isFa ? 'شماره کارت بانکی' : 'Bank card number' }}</label>
                        <input class="input number ltr" id="card-number" name="card_number" inputmode="numeric" autocomplete="cc-number" pattern="[0-9۰-۹ ]{16,19}" maxlength="19" required value="{{ old('card_number') }}" placeholder="0000 0000 0000 0000" data-iranian-card>
                        <p class="field-help">{{ $isFa ? 'کارت باید متعلق به همان کد ملی و همان نام صاحب حساب باشد.' : 'The card must belong to the same national ID and account holder.' }}</p>
                        @error('card_number')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="two-column">
                    <div class="field" data-kyc-field="national_id_image">
                        <span class="field-label {{ $needsCorrection ? '' : 'required' }}">{{ $isFa ? 'تصویر کارت ملی (خالی)' : 'National ID image (clean)' }}</span>
                        <label class="upload-box" for="national-card-image"><input id="national-card-image" name="national_id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" @required(!$needsCorrection) data-preview-input="#national-card-preview"><span class="upload-box-content"><span class="quick-icon"><x-icon name="upload" /></span><strong>{{ $needsCorrection ? ($isFa?'تعویض در صورت نیاز':'Replace only if needed') : ($isFa ? 'انتخاب یا گرفتن عکس' : 'Choose or take a photo') }}</strong><small class="muted">{{ $isFa ? 'چهار گوشه کارت و نوشته‌ها واضح باشد؛ کارت ملی بدون پوشش و خالی.' : 'Show all four corners with readable text; the national ID must be clean and unobstructed.' }}</small></span><img class="upload-preview" id="national-card-preview" alt=""></label>
                        @error('national_id_image')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field" data-kyc-field="selfie_with_id_image">
                        <span class="field-label {{ $needsCorrection ? '' : 'required' }}">{{ $isFa ? 'تصویر شخص همراه کارت ملی' : 'Selfie holding the ID' }}</span>
                        <label class="upload-box" for="selfie-image"><input id="selfie-image" name="selfie_with_id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="user" @required(!$needsCorrection) data-preview-input="#selfie-preview"><span class="upload-box-content"><span class="quick-icon"><x-icon name="upload" /></span><strong>{{ $needsCorrection ? ($isFa?'تعویض در صورت نیاز':'Replace only if needed') : ($isFa ? 'انتخاب یا گرفتن عکس' : 'Choose or take a photo') }}</strong><small class="muted">{{ $isFa ? 'چهره و کارت هر دو واضح و بدون بازتاب باشند.' : 'Keep both your face and ID clear and glare-free.' }}</small></span><img class="upload-preview" id="selfie-preview" alt=""></label>
                        @error('selfie_with_id_image')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="field" data-kyc-field="consent">
                    <label class="checkbox"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>{{ $isFa ? 'صحت اطلاعات را تأیید می‌کنم و با بررسی مدارک برای فعال‌سازی پرداخت ریالی موافقم. می‌دانم که احراز سریع معمولاً 1 ساعت و نهایتاً 24 ساعت طول می‌کشد.' : 'I confirm the information is accurate and consent to document review for rial payments. I understand fast verification usually takes 1 hour, at most 24 hours.' }}</span></label>
                    @error('consent')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button class="btn btn-primary btn-block" type="submit">{{ $isFa ? 'ارسال برای بررسی' : 'Submit for review' }}</button>
            </form>
            @endif
        @endif
    </section>
</div>

<script>
// ─── KYC form real-time field validation ──────────────────────────────
// Marks each .field wrapper with .is-valid (green) or .is-invalid (red)
// based on the field's HTML5 validity + a few custom rules (Persian-digit
// normalization, Iranian national ID + card checksum, file size).
//
// The states are recomputed on every input/change/blur event, so the
// user gets immediate feedback as they type. On a server-side error
// response, the @@error() directive sets .has-server-error on the
// corresponding field (which we then upgrade to .is-invalid).
//
// We ALSO mark fields green even when they have not been touched yet,
// as long as they have a valid value — this gives the user a clear
// sense of progress ("3 of 5 fields done"). Fields that are empty
// stay neutral (no class).
(function () {
    var form = document.querySelector('form[data-kyc-form]');
    if (!form) return;

    // Server-side error map: { field_name: error_message }
    var serverErrors = {};
    try {
        // Server-side errors are rendered in the @@error() blocks above.
        // We re-discover them by looking for .field-error siblings inside
        // each .field wrapper. This is the simplest way to bridge the
        // server-rendered error state with the JS-driven real-time state.
        form.querySelectorAll('[data-kyc-field]').forEach(function (wrapper) {
            var errEl = wrapper.querySelector('.field-error');
            if (errEl && errEl.textContent.trim()) {
                serverErrors[wrapper.dataset.kycField] = errEl.textContent.trim();
            }
        });
    } catch (e) {}

    function normalizePersianDigits(value) {
        return String(value).replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function (d) {
            var code = d.charCodeAt(0);
            if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
            if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
            return d;
        });
    }

    function isValidIranianNationalId(value) {
        var code = normalizePersianDigits(value).replace(/\D/g, '');
        if (code.length !== 10) return false;
        if (/^(\d)\1{9}$/.test(code)) return false;
        var sum = 0;
        for (var i = 0; i < 9; i++) sum += parseInt(code[i], 10) * (10 - i);
        var remainder = sum % 11;
        var check = remainder < 2 ? remainder : 11 - remainder;
        return check === parseInt(code[9], 10);
    }

    function isValidIranianCard(value) {
        var pan = normalizePersianDigits(value).replace(/\D/g, '');
        if (pan.length !== 16) return false;
        if (/^(\d)\1{15}$/.test(pan)) return false;
        var sum = 0;
        for (var i = 0; i < 16; i++) {
            var c = parseInt(pan[i], 10) * (i % 2 === 0 ? 2 : 1);
            sum += c > 9 ? c - 9 : c;
        }
        return sum % 10 === 0;
    }

    function isFieldValid(wrapper) {
        var name = wrapper.dataset.kycField;
        var input = wrapper.querySelector('input, select, textarea, input[type="checkbox"]');
        if (!input) return null; // can't determine
        // File inputs
        if (input.type === 'file') {
            // For file inputs: required unless this is a "needs correction"
            // case where re-upload is optional. We check the required
            // attribute as set by @required(!$needsCorrection).
            if (input.required) return input.files && input.files.length > 0;
            return null; // optional file field — neutral
        }
        // Checkbox
        if (input.type === 'checkbox') {
            return input.checked;
        }
        // Text inputs
        var raw = (input.value || '').trim();
        if (raw === '') return false; // empty
        // Field-specific rules
        if (name === 'legal_name') return raw.length >= 3 && raw.length <= 120;
        if (name === 'national_id') return isValidIranianNationalId(raw);
        if (name === 'card_number') return isValidIranianCard(raw);
        // Fallback: rely on HTML5 validity
        return input.checkValidity();
    }

    function updateFieldState(wrapper) {
        var name = wrapper.dataset.kycField;
        var isValid = isFieldValid(wrapper);
        wrapper.classList.remove('is-valid', 'is-invalid');
        if (serverErrors[name]) {
            // Server-side error always wins
            wrapper.classList.add('is-invalid');
        } else if (isValid === true) {
            wrapper.classList.add('is-valid');
        } else if (isValid === false) {
            // Mark invalid ONLY if the field has been touched or is required
            // — empty optional fields stay neutral.
            var input = wrapper.querySelector('input, select, textarea, input[type="checkbox"]');
            var isTouched = input && (input.type === 'checkbox' || (input.value || '').trim() !== '');
            if (input && input.required) wrapper.classList.add('is-invalid');
            else if (isTouched) wrapper.classList.add('is-invalid');
        }
    }

    function updateAll() {
        form.querySelectorAll('[data-kyc-field]').forEach(updateFieldState);
    }

    // Attach listeners
    form.querySelectorAll('[data-kyc-field] input, [data-kyc-field] select, [data-kyc-field] textarea').forEach(function (input) {
        input.addEventListener('input', function () {
            var wrapper = input.closest('[data-kyc-field]');
            if (wrapper) updateFieldState(wrapper);
        });
        input.addEventListener('change', function () {
            var wrapper = input.closest('[data-kyc-field]');
            if (wrapper) updateFieldState(wrapper);
        });
        input.addEventListener('blur', function () {
            var wrapper = input.closest('[data-kyc-field]');
            if (wrapper) updateFieldState(wrapper);
        });
    });

    // Initial state
    updateAll();

    // ─── Auto-scroll to first invalid field on server-side error ──────
    // When the form comes back from the server with validation errors,
    // we want the user's browser to scroll the FIRST invalid field into
    // view (smoothly) so they don't have to hunt for what went wrong.
    // This runs once on page load — after updateAll() has marked each
    // field with .is-valid / .is-invalid based on the server-side
    // .field-error text.
    //
    // We use requestAnimationFrame so the DOM has fully painted the
    // is-invalid classes before we measure scroll positions.
    if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(function () {
            var firstInvalid = form.querySelector('.field.is-invalid');
            if (firstInvalid) {
                // Scroll the field's INPUT (not the wrapper) into view,
                // because the wrapper has no fixed height — scrolling to
                // the input ensures the field's label + help text are
                // both visible above the input.
                var target = firstInvalid.querySelector('input, select, textarea') || firstInvalid;
                try {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Also focus the field so keyboard users can start
                    // typing immediately. Use a small timeout so the
                    // smooth-scroll animation can begin first.
                    setTimeout(function () {
                        try { target.focus({ preventScroll: true }); } catch (e) {}
                    }, 350);
                } catch (e) {
                    // Older browsers don't support scrollIntoView options —
                    // fall back to a hard jump.
                    if (target.scrollIntoView) target.scrollIntoView();
                }
            }
        });
    }
})();
</script>
@endsection
