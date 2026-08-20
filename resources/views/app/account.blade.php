@extends('layouts.app')

@section('title', (app()->isLocale('fa') ? 'حساب کاربری' : 'Account') . ' — ' . __('ui.brand'))

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $currentUser = $user ?? auth()->user();
    $name = data_get($currentUser, 'display_name') ?: trim(data_get($currentUser, 'first_name').' '.data_get($currentUser, 'last_name')) ?: ($isFa ? 'کاربر تلگرام' : 'Telegram user');
    $username = data_get($currentUser, 'telegram_username');
    $avatar = data_get($currentUser, 'id') ? \App\Providers\AppServiceProvider::avatarUrl($currentUser) : null;
    $level = data_get($currentUser, 'kyc_level', 'base');
    $level = $level instanceof \BackedEnum ? $level->value : (string) $level;

    // Verified identity summary (only shown when KYC is approved).
    // $approvedKyc is supplied by PageController::account(); it's the user's
    // most-recent APPROVED KycApplication. We extract the legal name (auto-
    // decrypted by the model's cast) and a masked national ID for display.
    $approvedKyc = $approvedKyc ?? null;
    $verifiedName = data_get($approvedKyc, 'legal_name_encrypted');
    $verifiedNationalIdRaw = (string) data_get($approvedKyc, 'national_id_encrypted', '');
    $verifiedNationalIdMasked = '';
    if ($verifiedNationalIdRaw !== '' && strlen($verifiedNationalIdRaw) >= 6) {
        $verifiedNationalIdMasked = mb_substr($verifiedNationalIdRaw, 0, 3).'******'.mb_substr($verifiedNationalIdRaw, -3);
    } elseif ($verifiedNationalIdRaw !== '') {
        $verifiedNationalIdMasked = str_repeat('*', strlen($verifiedNationalIdRaw));
    }
    $verifiedAt = data_get($approvedKyc, 'reviewed_at');
    $verifiedAtFormatted = $verifiedAt
        ? \App\Support\PersianDate::format(\Illuminate\Support\Carbon::parse($verifiedAt))
        : '—';
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $isFa ? 'تنظیمات شخصی' : 'Personal settings' }}</div><h1 class="page-title">{{ $isFa ? 'حساب کاربری' : 'Account' }}</h1></div></header>

<section class="card">
    <div class="user-hero"><span class="avatar avatar-lg">@if($avatar)<img src="{{ $avatar }}" alt="" decoding="async" loading="lazy" onerror="this.style.display='none'; this.parentElement.classList.add('avatar-fallback')"><span class="avatar-initial" aria-hidden="true" style="display:none">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>@else{{ mb_strtoupper(mb_substr($name, 0, 1)) }}@endif</span><div class="user-hero-copy"><h1>{{ $name }}</h1><div class="muted ltr">{{ $username ? '@'.ltrim($username, '@') : 'Telegram ID: '.data_get($currentUser, 'telegram_user_id', '—') }}</div><div class="cluster" style="margin-top:8px"><x-status-chip :value="$level" /><span class="chip">{{ strtoupper(data_get($currentUser, 'locale', app()->getLocale())) }}</span></div></div></div>
</section>

@if($approvedKyc)
    <section class="section card">
        <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اطلاعات هویتی تأییدشده' : 'Verified identity' }}</h2><p class="card-subtitle">{{ $isFa ? 'پس از تأیید ادمین، این اطلاعات در پروفایل شما ثبت شده است.' : 'Recorded in your profile after admin approval.' }}</p></div><x-icon name="identity" /></div>
        <dl class="definition-list">
            <div class="definition-row"><dt>{{ $isFa ? 'نام قانونی' : 'Legal name' }}</dt><dd>{{ $verifiedName ?: '—' }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'کد ملی' : 'National ID' }}</dt><dd class="number ltr">{{ $verifiedNationalIdMasked ?: '—' }}</dd></div>
            <div class="definition-row"><dt>{{ $isFa ? 'تاریخ تأیید' : 'Approved at' }}</dt><dd class="number">{{ $verifiedAtFormatted }}</dd></div>
        </dl>
        <a class="btn btn-text btn-sm" href="{{ $safeRoute('app.identity.show') }}">{{ $isFa ? 'مشاهده کامل احراز هویت' : 'View full identity' }}<x-icon name="chevron" /></a>
    </section>
@endif

<section class="section stack-sm">
    <a class="quick-action" href="{{ $safeRoute('app.identity.show') }}"><span class="quick-icon"><x-icon name="identity" /></span><span style="flex:1"><strong>{{ $isFa ? 'احراز هویت و کارت‌ها' : 'Identity and bank cards' }}</strong><small>{{ $level === 'rial_verified' ? ($isFa ? 'پرداخت ریالی فعال است' : 'Rial payments enabled') : ($isFa ? 'برای پرداخت ریالی تکمیل کنید' : 'Complete for rial payments') }}</small></span><x-icon name="chevron" /></a>
    <a class="quick-action" href="{{ $safeRoute('app.support.index') }}"><span class="quick-icon"><x-icon name="support" /></span><span style="flex:1"><strong>{{ $isFa ? 'پشتیبانی' : 'Support' }}</strong><small>{{ $isFa ? 'تیکت‌ها و گفتگو با پشتیبانی' : 'Tickets and conversations' }}</small></span><x-icon name="chevron" /></a>
    <a class="quick-action" href="{{ $safeRoute('app.help') }}"><span class="quick-icon"><x-icon name="document" /></span><span style="flex:1"><strong>{{ $isFa ? 'راهنما و قوانین' : 'Help and policies' }}</strong><small>{{ $isFa ? 'راهنمای ثبت و سیاست‌های تبلیغات' : 'Setup guides and advertising policies' }}</small></span><x-icon name="chevron" /></a>
</section>

<section class="section card">
    <div class="card-head"><div><h2 class="card-title">{{ $isFa ? 'اطلاعات حساب' : 'Account details' }}</h2></div></div>
    <dl class="definition-list"><div class="definition-row"><dt>Telegram ID</dt><dd class="number ltr">{{ data_get($currentUser, 'telegram_user_id', '—') }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'شماره تلفن' : 'Phone number' }}</dt><dd class="number ltr">{{ data_get($currentUser, 'phone', '—') }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'زبان' : 'Language' }}</dt><dd>{{ $isFa ? 'فارسی' : 'English' }}</dd></div><div class="definition-row"><dt>{{ $isFa ? 'وضعیت حساب' : 'Account status' }}</dt><dd><x-status-chip :value="data_get($currentUser, 'account_status', 'active')" /></dd></div></dl>
</section>

<section class="section card">
    <div class="card-head">
        <div>
            <h2 class="card-title">{{ $isFa ? 'سازنده ربات و پروژه' : 'Project creator' }}</h2>
            <p class="card-subtitle">{{ $isFa ? 'اطلاعات رسمی سازنده و راه ارتباط مستقیم.' : 'Official creator information and direct contact.' }}</p>
        </div>
        <x-icon name="send" />
    </div>
    <div class="user-hero" style="align-items:center">
        <span class="avatar avatar-lg">MR</span>
        <div class="user-hero-copy">
            <h1 style="font-size:18px">Milad Rajabi | میلاد رجبی</h1>
            <div class="muted ltr">@MiladRajabi2002</div>
        </div>
    </div>
    <a class="btn btn-secondary btn-block" style="margin-top:14px" href="https://t.me/MiladRajabi2002" target="_blank" rel="noopener noreferrer">
        <x-icon name="send" />{{ $isFa ? 'ارتباط با میلاد رجبی در تلگرام' : 'Contact Milad Rajabi on Telegram' }}
    </a>
</section>

@if(\Illuminate\Support\Facades\Route::has('app.logout'))
    <form class="section" action="{{ route('app.logout') }}" method="post">@csrf<button class="btn btn-danger btn-block" type="submit"><x-icon name="logout" />{{ __('ui.actions.logout') }}</button></form>
@endif
@endsection
