@extends('layouts.admin')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static function (string $name, array $parameters = []): string {
        if (! \Illuminate\Support\Facades\Route::has($name)) return '#';
        try { return route($name, $parameters); } catch (\Throwable) { return '#'; }
    };
    $application = $application ?? $kycApplication ?? null;
    $id = data_get($application,'id');
    $user = data_get($application,'user') ?: $customer ?? null;
    $status = data_get($application,'status','submitted');
    $cards = collect(data_get($application,'cards',$fundingCards ?? []));
    $reviews = collect(data_get($application,'reviews',$kycReviews ?? []))->sortByDesc('created_at');
    $urls = $documentUrls ?? [];
    $nationalCardUrl = data_get($urls,'national_id_image') ?: data_get($urls,'national_id') ?: data_get($urls,'national_card') ?: data_get($urls,'national_card_front') ?: data_get($documents ?? [],'national_id_image') ?: data_get($documents ?? [],'national_card');
    $selfieUrl = data_get($urls,'selfie_with_id_image') ?: data_get($urls,'selfie_with_id') ?: data_get($urls,'selfie_with_card') ?: data_get($documents ?? [],'selfie_with_id_image') ?: data_get($documents ?? [],'selfie_with_card');
    $reasonItems = collect($reasonCodes ?? []);
    $reasonLabelsFa = [
        'card_owner_mismatch' => 'مغایرت صاحب کارت و هویت',
        'document_unreadable' => 'تصویر یا مدرک ناخوانا',
        'selfie_mismatch' => 'عدم تطابق چهره',
        'identity_data_mismatch' => 'مغایرت اطلاعات هویتی',
        'suspected_fraud' => 'نیازمند بررسی تقلب',
    ];
    $formatDate = static function ($value): string { if (!$value) return '—'; try { return \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($value)); } catch (\Throwable) { return (string) $value; } };
    $formatCardNumber = static function ($value): string {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        return $digits !== '' ? trim(chunk_split($digits, 4, ' ')) : '—';
    };
    // Pick the first pending/approved card automatically so the admin doesn't
    // have to choose from a single-card dropdown just to confirm the KYC.
    $autoCard = $cards->first(fn ($c) => in_array(data_get($c,'status','pending'), ['pending','approved'], true)) ?: $cards->first();
    // Keep older KYC attempts visible from the current review page. The
    // controller already eager-loads user.kycApplications, so this does not
    // add extra queries and preserves rejected/correction history across
    // resubmissions.
    $previousApplications = collect(data_get($user,'kycApplications',[]))
        ->filter(fn ($item) => (int) data_get($item,'id') !== (int) $id)
        ->sortByDesc(fn ($item) => (int) data_get($item,'version',0))
        ->take(10);
@endphp
@section('title', $isFa ? 'بررسی احراز هویت' : 'Review identity')
@section('page-title', $isFa ? 'بررسی احراز هویت' : 'Identity review')
@section('page-kicker', '#'.($id ?: '—'))

<header class="page-header"><div><div class="cluster"><h1 class="page-title">{{ data_get($user,'display_name',$isFa?'کاربر':'User') }}</h1><x-status-chip :value="$status" /></div><p class="page-lead">{{ $isFa ? 'تطابق هویت، تصاویر و مالکیت کارت را جداگانه بررسی کنید.' : 'Review identity, document quality, and card ownership separately.' }}</p></div><div class="page-header-actions"><a class="btn btn-secondary" href="{{ $safeRoute('admin.users.show',['user'=>data_get($user,'id')]) }}"><x-icon name="user" />{{ $isFa?'پرونده کاربر':'User file' }}</a></div></header>

<div class="review-layout">
    <aside class="stack">
        <section class="card"><div class="user-hero"><span class="avatar avatar-lg">@if(data_get($user,'id'))<img src="@avatarUrl($user)" alt="" decoding="async" loading="lazy" onerror="this.style.display='none'; this.parentElement.classList.add('avatar-fallback')"><span class="avatar-initial" aria-hidden="true" style="display:none">{{ mb_strtoupper(mb_substr((string)data_get($user,'display_name','U'),0,1)) }}</span>@else{{ mb_strtoupper(mb_substr((string)data_get($user,'display_name','U'),0,1)) }}@endif</span><div class="user-hero-copy"><h1 style="font-size:19px">{{ data_get($user,'display_name','—') }}</h1><div class="muted ltr">{{ data_get($user,'telegram_username') ? '@'.ltrim(data_get($user,'telegram_username'),'@') : 'ID '.data_get($user,'telegram_user_id','—') }}</div></div></div><hr class="divider"><dl class="definition-list"><div class="definition-row"><dt>{{ $isFa?'تلفن':'Phone' }}</dt><dd class="number ltr">{{ data_get($user,'phone','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'نام قانونی':'Legal name' }}</dt><dd>{{ data_get($application,'legal_name_encrypted','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'کد ملی':'National ID' }}</dt><dd class="number ltr">{{ data_get($application,'national_id_encrypted','—') }}</dd></div><div class="definition-row"><dt>{{ $isFa?'ارسال':'Submitted' }}</dt><dd class="number">{{ $formatDate(data_get($application,'submitted_at')) }}</dd></div></dl></section>
        <section class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'کارت بانکی':'Bank card' }}</h2><p class="card-subtitle">{{ $isFa?'شماره کامل فقط برای مدیر بررسی‌کننده نمایش داده می‌شود.':'The full number is visible only on this audited admin review page.' }}</p></div></div>@forelse($cards as $card)<div class="option-card"><span class="quick-icon"><x-icon name="card" /></span><span class="option-card-copy"><strong class="number ltr">{{ $formatCardNumber(data_get($card,'pan_encrypted')) }}</strong><small>{{ data_get($card,'holder_name_search',$isFa?'نام رمزگذاری‌شده':'Encrypted name') }}</small></span><x-status-chip :value="data_get($card,'status','pending')" /></div>@empty<p class="muted">{{ $isFa?'کارت ثبت نشده است.':'No card recorded.' }}</p>@endforelse</section>
    </aside>

    <section class="stack">
        <div class="notice notice-warning"><x-icon name="lock" /><p>{{ $isFa ? 'مشاهده مدارک حساس ثبت می‌شود. تصویر را دانلود یا خارج از سامانه ذخیره نکنید.' : 'Sensitive document access is audited. Do not download or store images outside the system.' }}</p></div>
        <div class="two-column">
            <article class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'کارت ملی':'National ID' }}</h2><p class="card-subtitle">{{ $isFa?'چهار گوشه و نوشته‌ها':'Corners and text must be clear' }}</p></div></div>@if($nationalCardUrl)<div class="sensitive-media"><img src="{{ $nationalCardUrl }}" alt="{{ $isFa?'تصویر کارت ملی':'National ID document' }}"><button class="btn btn-secondary sensitive-media-action" type="button" data-reveal-sensitive aria-expanded="false"><x-icon name="eye" />{{ $isFa?'نمایش امن':'Reveal securely' }}</button></div>@else<x-empty-state icon="document" :description="$isFa?'تصویر در دسترس نیست.':'Image unavailable.'" style="min-height:230px" />@endif</article>
            <article class="card"><div class="card-head"><div><h2 class="card-title">{{ $isFa?'شخص همراه کارت':'Selfie with ID' }}</h2><p class="card-subtitle">{{ $isFa?'تطابق چهره و کارت':'Compare the face and document' }}</p></div></div>@if($selfieUrl)<div class="sensitive-media"><img src="{{ $selfieUrl }}" alt="{{ $isFa?'تصویر شخص همراه کارت ملی':'Selfie holding national ID' }}"><button class="btn btn-secondary sensitive-media-action" type="button" data-reveal-sensitive aria-expanded="false"><x-icon name="eye" />{{ $isFa?'نمایش امن':'Reveal securely' }}</button></div>@else<x-empty-state icon="user" :description="$isFa?'تصویر در دسترس نیست.':'Image unavailable.'" style="min-height:230px" />@endif</article>
        </div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $isFa?'سوابق بررسی':'Review history' }}</h2></div></div>
            @if($reviews->isEmpty())
                <p class="muted">{{ $isFa?'هنوز تصمیمی برای این پرونده ثبت نشده است.':'No decision has been recorded for this application.' }}</p>
            @else
                <ul class="timeline">@foreach($reviews as $review)<li class="timeline-item"><span class="timeline-dot"></span><span class="timeline-copy"><strong>{{ data_get($review,'decision','—') }} · {{ data_get($review,'admin.name',data_get($review,'reviewer.name','—')) }}</strong>@if(data_get($review,'note'))<span>{{ data_get($review,'note') }}</span>@endif<small class="number">{{ $formatDate(data_get($review,'created_at')) }}</small></span></li>@endforeach</ul>
            @endif

            @if($previousApplications->isNotEmpty())
                <hr class="divider">
                <div class="card-head"><div><h3 class="card-title" style="font-size:16px">{{ $isFa?'پرونده‌های قبلی این کاربر':'Previous KYC applications' }}</h3><p class="card-subtitle">{{ $isFa?'رد، درخواست اصلاح و ارسال‌های قبلی حذف نمی‌شوند.':'Rejected, correction, and previous submissions remain accessible.' }}</p></div></div>
                <ul class="timeline">
                    @foreach($previousApplications as $previousApplication)
                        @php
                            $previousId = data_get($previousApplication,'id');
                            $previousVersion = data_get($previousApplication,'version','—');
                            $previousStatus = data_get($previousApplication,'status','draft');
                            $previousDate = data_get($previousApplication,'submitted_at',data_get($previousApplication,'created_at'));
                        @endphp
                        <li class="timeline-item">
                            <span class="timeline-dot"></span>
                            <span class="timeline-copy">
                                <a href="{{ $safeRoute('admin.kyc.show',['application'=>$previousId]) }}"><strong>{{ $isFa?'پرونده':'Application' }} #{{ $previousId }} · {{ $isFa?'نسخه':'Version' }} {{ $previousVersion }}</strong></a>
                                <span><x-status-chip :value="$previousStatus" /></span>
                                <small class="number">{{ $formatDate($previousDate) }}</small>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </section>

    <aside class="card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa?'تصمیم نهایی':'Decision' }}</h2><p class="card-subtitle">{{ $isFa?'گزینه موردنظر را انتخاب و ارسال کنید.':'Pick one option and submit.' }}</p></div></div>
        <form class="form-grid" action="{{ $safeRoute('admin.kyc.decision',['application'=>$id]) }}" method="post" data-loading-form id="kyc-decision-form">@csrf
            {{-- Hidden card_id auto-picks the first reviewable card so the admin
                 never has to interact with a single-option dropdown. --}}
            @if($autoCard)<input type="hidden" name="card_id" value="{{ data_get($autoCard,'id') }}">@endif
            {{-- Hidden decision field — the four submit buttons below set
                 this.value before the form actually submits. This is more
                 reliable than relying on the browser to forward name/value
                 of the clicked submit button (which fails on some mobile
                 WebViews, especially Telegram's in-app browser). --}}
            <input type="hidden" name="decision" value="" data-decision-input>
            @foreach([
                'phone_verified' => $isFa?'شماره تلفن تأیید شده':'Phone is verified',
                'national_id_readable' => $isFa?'تصویر کارت ملی واضح و خواناست':'National ID is clear and readable',
                'selfie_matches_identity' => $isFa?'چهره با هویت مطابقت دارد':'Selfie matches the identity',
                'card_owner_matches_identity' => $isFa?'صاحب کارت با هویت مطابقت دارد':'Card owner matches the identity',
                'card_number_verified' => $isFa?'شماره کارت بانکی کامل بررسی و تأیید شد':'Full bank card number was reviewed and verified',
            ] as $name => $label)<label class="checkbox"><input type="checkbox" name="checklist[{{ $name }}]" value="1" @checked(old('checklist.'.$name))><span>{{ $label }}</span></label>@endforeach
            <div class="field"><label class="field-label" for="reason-code">{{ $isFa?'دلیل رد/اصلاح (الزامی برای رد یا درخواست اصلاح)':'Rejection or correction reason (required for reject/change)' }}</label><select class="select" id="reason-code" name="reason_code"><option value="">{{ $isFa?'انتخاب دلیل':'Choose a reason' }}</option>@foreach($reasonItems as $reason)@php($reasonValue = $reason instanceof \BackedEnum ? $reason->value : (string)data_get($reason,'value',$reason))<option value="{{ $reasonValue }}" @selected(old('reason_code') === $reasonValue)>{{ $isFa && is_object($reason) && method_exists($reason,'label') ? $reason->label() : str($reasonValue)->replace('_',' ')->headline() }}</option>@endforeach</select></div>
            <div class="field" id="other-reason-title-field" @if(old('reason_code') !== 'other') hidden @endif><label class="field-label" for="other-reason-title">{{ $isFa?'عنوان دلیل سایر موارد':'Other reason title' }}</label><input class="input" id="other-reason-title" name="other_reason_title" maxlength="120" value="{{ old('other_reason_title') }}" placeholder="{{ $isFa?'مثلاً: مغایرت اطلاعات تکمیلی':'e.g. Additional information mismatch' }}">@error('other_reason_title')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label class="field-label" for="review-note">{{ $isFa?'پیام کاربر و یادداشت':'User-facing note' }}</label><textarea class="textarea" id="review-note" name="note" maxlength="2000" placeholder="{{ $isFa?'علت و روش اصلاح را روشن بنویسید.':'Explain the reason and recovery action clearly.' }}">{{ old('note') }}</textarea></div>
            <button class="btn btn-primary btn-block" data-decision-btn="approved" type="submit"><x-icon name="check" />{{ $isFa?'تأیید احراز هویت':'Approve identity' }}</button>
            <button class="btn btn-warning btn-block" data-decision-btn="changes_requested" type="submit"><x-icon name="edit" />{{ $isFa?'درخواست اصلاح':'Request changes' }}</button>
            <button class="btn btn-secondary btn-block" data-decision-btn="manual_attention" type="submit"><x-icon name="warning" />{{ $isFa?'ارجاع برای بررسی بیشتر':'Escalate for review' }}</button>
            <button class="btn btn-danger btn-block" data-decision-btn="rejected_permanent" type="submit"><x-icon name="warning" />{{ $isFa?'رد نهایی':'Reject permanently' }}</button>
        </form>
        <script>
        // Copy the clicked button's decision value into the hidden input
        // BEFORE the form actually submits. This guarantees the server
        // always receives the correct decision, even in WebViews that
        // don't forward name/value of the clicked submit button reliably
        // (notably Telegram's in-app browser).
        (function () {
            var form = document.getElementById('kyc-decision-form');
            if (!form) return;
            var decisionInput = form.querySelector('[data-decision-input]');
            if (!decisionInput) return;
            form.querySelectorAll('[data-decision-btn]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    decisionInput.value = btn.getAttribute('data-decision-btn') || '';
                });
            });

            var reasonSelect = form.querySelector('#reason-code');
            var otherReasonField = form.querySelector('#other-reason-title-field');
            var otherReasonInput = form.querySelector('#other-reason-title');
            function syncOtherReasonField() {
                if (!reasonSelect || !otherReasonField || !otherReasonInput) return;
                var isOther = reasonSelect.value === 'other';
                otherReasonField.hidden = !isOther;
                otherReasonInput.required = isOther;
                if (!isOther) otherReasonInput.value = '';
            }
            if (reasonSelect) {
                reasonSelect.addEventListener('change', syncOtherReasonField);
                syncOtherReasonField();
            }
        })();
        </script>
    </aside>
</div>
@endsection
