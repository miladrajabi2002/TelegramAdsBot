@extends('layouts.admin')

@section('title', __('ui.admin_nav.settings'))
@section('page-title', __('ui.admin_nav.settings'))
@section('page-kicker', app()->isLocale('fa') ? 'قیمت‌گذاری و سلامت اتصالات' : 'Pricing and integration health')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $values = $settings ?? [];
    $pricing = $pricing ?? $values;
    $gateways = collect($gatewayStatus ?? []);
    $callbackUrls = $callbackUrls ?? [];
@endphp

<header class="page-header"><div><div class="eyebrow">{{ $isFa?'تنظیمات حساس':'Sensitive operations' }}</div><h1 class="page-title">{{ $isFa?'قیمت‌گذاری و درگاه‌ها':'Pricing & gateways' }}</h1><p class="page-lead">{{ $isFa?'تغییرات در سفارش‌های جدید اعمال می‌شود؛ Quoteهای قبلی مطابق مهلت خود معتبرند.':'Changes affect new orders; existing quotes remain valid until their own expiry.' }}</p></div></header>

<form action="{{ $safeRoute('admin.settings.update') }}" method="post" class="stack" data-loading-form>@csrf @method('PUT')
    <div class="dashboard-layout">
        <section class="stack">
            <article class="card">
                <div class="card-head"><div><h2 class="card-title">{{ $isFa?'مدل درآمد':'Revenue model' }}</h2><p class="card-subtitle">{{ $isFa?'ارقام هر Quote با Snapshot ذخیره می‌شوند.':'Every quote stores an immutable pricing snapshot.' }}</p></div></div>
                <div class="form-grid">
                    <div class="field-row">
                        <div class="field"><label class="field-label required" for="markup-percent">{{ $isFa?'سود خدمات':'Service markup' }}</label><div class="input-with-suffix"><input class="input number" id="markup-percent" name="markup_percent" type="number" min="0" max="100" step="0.01" required value="{{ old('markup_percent',data_get($pricing,'markup_percent',((int)data_get($pricing,'service_markup_bps',1500))/100)) }}"><span>%</span></div></div>
                        <div class="field"><label class="field-label required" for="gateway-fee">{{ $isFa?'کارمزد درگاه':'Gateway fee' }}</label><div class="input-with-suffix"><input class="input number" id="gateway-fee" name="gateway_fee_percent" type="number" min="0" max="20" step="0.01" required value="{{ old('gateway_fee_percent',data_get($pricing,'gateway_fee_percent',((int)data_get($pricing,'gateway_fee_bps',0))/100)) }}"><span>%</span></div></div>
                    </div>
                    <div class="field-row">
                        <div class="field"><label class="field-label required" for="min-campaign">{{ $isFa?'حداقل سفارش':'Minimum order' }}</label><div class="input-with-suffix"><input class="input number" id="min-campaign" name="minimum_campaign_toman" type="number" min="1000" step="1000" required value="{{ old('minimum_campaign_toman',data_get($pricing,'minimum_campaign_toman',intdiv((int)data_get($pricing,'minimum_order_irr',100000),10))) }}"><span>{{ $isFa?'تومان':'Toman' }}</span></div></div>
                        <div class="field"><label class="field-label required" for="quote-ttl">{{ $isFa?'اعتبار Quote':'Quote validity' }}</label><div class="input-with-suffix"><input class="input number" id="quote-ttl" name="quote_ttl_minutes" type="number" min="1" max="1440" required value="{{ old('quote_ttl_minutes',data_get($pricing,'quote_ttl_minutes',30)) }}"><span>{{ $isFa?'دقیقه':'min' }}</span></div></div>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head"><div><h2 class="card-title">{{ $isFa?'نرخ تبدیل':'Exchange rates' }}</h2><p class="card-subtitle">{{ $isFa?'نرخ منبع، زمان به‌روزرسانی و Margin را شفاف نگه دارید.':'Keep the source rate, refresh time, and conversion margin explicit.' }}</p></div></div>
                <div class="form-grid">
                    <div class="field-row"><div class="field"><label class="field-label required" for="usd-toman">USD / Toman</label><input class="input number" id="usd-toman" name="usd_to_toman_rate" type="number" min="1" step="1" required value="{{ old('usd_to_toman_rate',data_get($values,'usd_to_toman_rate',data_get($values,'usd_to_irr') ? ((int)data_get($values,'usd_to_irr')/10) : null)) }}"></div><div class="field"><label class="field-label required" for="gram-usd">GRAM / USD</label><input class="input number" id="gram-usd" name="gram_to_usd" type="number" min="0.000001" step="0.000001" required value="{{ old('gram_to_usd',data_get($values,'gram_to_usd')) }}"></div></div>
                    <div class="field"><label class="field-label" for="conversion-margin">{{ $isFa?'حاشیه تبدیل':'Conversion margin' }}</label><div class="input-with-suffix"><input class="input number" id="conversion-margin" name="conversion_margin_percent" type="number" min="0" max="25" step="0.01" value="{{ old('conversion_margin_percent',data_get($pricing,'conversion_margin_percent',0)) }}"><span>%</span></div></div>
                    <input type="hidden" name="automatic_exchange_rate" value="0"><label class="checkbox" aria-disabled="true"><input type="checkbox" disabled><span>{{ $isFa?'نرخ در نسخه فعلی دستی است؛ Price Feed پس از اتصال و آزمون سرور فعال می‌شود.':'Rates are manual in this version; the price feed will be enabled only after server integration and testing.' }}</span></label>
                </div>
            </article>
        </section>

        <aside class="stack">
            <section class="card">
                <div class="card-head"><div><h2 class="card-title">{{ $isFa?'وضعیت اتصالات':'Connection health' }}</h2><p class="card-subtitle">{{ $isFa?'کلیدها هرگز در پنل نمایش داده نمی‌شوند.':'Secrets are never rendered in the admin UI.' }}</p></div></div>
                <div class="stack">
                    @forelse($gateways as $key=>$gateway)
                        @php
                            $gatewayName = is_int($key) ? data_get($gateway,'name','Gateway') : $key;
                            $connected = (bool) data_get($gateway,'connected',data_get($gateway,'configured',false));
                        @endphp
                        <div class="option-card"><span class="quick-icon"><x-icon name="{{ $gatewayName==='telegram'?'send':($gatewayName==='nowpayments'?'globe':'card') }}" /></span><span class="option-card-copy"><strong>{{ str((string)$gatewayName)->headline() }}</strong><small>{{ data_get($gateway,'message',$connected?($isFa?'پیکربندی شده':'Configured'):($isFa?'نیازمند تنظیم .env':'Needs .env configuration')) }}</small></span><x-status-chip :value="$connected?'verified':'pending'" :label="$connected?($isFa?'متصل':'Connected'):($isFa?'ناقص':'Incomplete')" /></div>
                    @empty
                        <div class="notice"><x-icon name="settings" /><p>{{ $isFa?'وضعیت درگاه‌ها توسط کنترلر ارسال می‌شود.':'Gateway health will appear when supplied by the controller.' }}</p></div>
                    @endforelse
                </div>
            </section>

            <section class="card"><div class="card-head"><div><h2 class="card-title">Callback / IPN</h2><p class="card-subtitle">{{ $isFa?'این URLها را عیناً در پنل درگاه قرار دهید.':'Paste these exact URLs into the gateway dashboards.' }}</p></div></div><div class="form-grid">@forelse(collect($callbackUrls) as $name=>$url)<div class="field"><label class="field-label">{{ str((string)$name)->headline() }}</label><div class="cluster"><input class="input ltr" style="flex:1" readonly value="{{ $url }}"><button class="icon-btn" type="button" data-copy="{{ $url }}" aria-label="{{ __('ui.actions.copy') }}"><x-icon name="copy" /></button></div></div>@empty<p class="muted">{{ $isFa?'آدرس‌ها پس از تنظیم APP_URL نمایش داده می‌شوند.':'URLs appear after APP_URL is configured.' }}</p>@endforelse</div></section>
        </aside>
    </div>

    <div class="sticky-actions"><div><strong>{{ $isFa?'ثبت تغییرات عملیاتی':'Save operational settings' }}</strong><div class="subtle">{{ $isFa?'این اقدام در Audit Log ثبت می‌شود.':'This action is recorded in the audit log.' }}</div></div><button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('ui.actions.save') }}</button></div>
</form>
@endsection
