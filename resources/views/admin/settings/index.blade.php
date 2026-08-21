@extends('layouts.admin')

@section('title', __('ui.admin_nav.settings'))
@section('page-title', __('ui.admin_nav.settings'))
@section('page-kicker', app()->isLocale('fa') ? 'قیمت‌گذاری و سلامت اتصالات' : 'Pricing and integration health')

@section('content')
@php
    $isFa = app()->isLocale('fa');
    $safeRoute = static fn (string $name, array $parameters = []) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : '#';
    $pricing = $pricing ?? ($settings ?? []);
    $rates = $liveRates ?? [];
    $gateways = collect($gatewayStatus ?? []);
    $callbackUrls = $callbackUrls ?? [];

    $rateState = static function (string $state) use ($isFa): array {
        return match ($state) {
            'live' => ['verified', $isFa ? 'زنده' : 'Live'],
            'cached' => ['verified', $isFa ? 'کش ۶۰ ثانیه' : '60s cache'],
            'last_good' => ['pending', $isFa ? 'آخرین نرخ معتبر' : 'Last known good'],
            default => ['pending', $isFa ? 'نرخ اضطراری' : 'Emergency fallback'],
        };
    };

    [$usdStateValue, $usdStateLabel] = $rateState((string) data_get($rates, 'usd_state', 'fallback'));
    [$tonStateValue, $tonStateLabel] = $rateState((string) data_get($rates, 'ton_state', 'fallback'));

    $formatRateTime = static function ($value) use ($isFa): string {
        if (!$value) return $isFa ? 'هنوز نرخ موفقی ثبت نشده' : 'No successful quote recorded yet';
        try {
            $date = \Illuminate\Support\Carbon::parse($value);
            return $isFa
                ? \App\Support\PersianDate::format($date)
                : $date->timezone(config('ads-platform.display_timezone', 'Asia/Tehran'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    };
@endphp

<header class="page-header">
    <div>
        <div class="eyebrow">{{ $isFa ? 'تنظیمات حساس' : 'Sensitive operations' }}</div>
        <h1 class="page-title">{{ $isFa ? 'قیمت‌گذاری و درگاه‌ها' : 'Pricing & gateways' }}</h1>
        <p class="page-lead">{{ $isFa ? 'نرخ‌های بازار خودکار هستند؛ فقط مدل درآمد و محدودیت‌های عملیاتی از این صفحه تغییر می‌کنند.' : 'Market rates are automatic; only revenue and operational rules are editable here.' }}</p>
    </div>
</header>

<form action="{{ $safeRoute('admin.settings.update') }}" method="post" class="stack" data-loading-form>
    @csrf
    @method('PUT')

    <div class="dashboard-layout">
        <section class="stack">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ $isFa ? 'مدل درآمد' : 'Revenue model' }}</h2>
                        <p class="card-subtitle">{{ $isFa ? 'ارقام هر Quote با Snapshot ذخیره می‌شوند.' : 'Every quote stores an immutable pricing snapshot.' }}</p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field-row">
                        <div class="field">
                            <label class="field-label required" for="markup-percent">{{ $isFa ? 'سود خدمات' : 'Service markup' }}</label>
                            <div class="input-with-suffix">
                                <input class="input number" id="markup-percent" name="markup_percent" type="number" min="0" max="100" step="0.01" required value="{{ old('markup_percent', data_get($pricing, 'markup_percent', 15)) }}">
                                <span>%</span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="field-label required" for="gateway-fee">{{ $isFa ? 'کارمزد درگاه' : 'Gateway fee' }}</label>
                            <div class="input-with-suffix">
                                <input class="input number" id="gateway-fee" name="gateway_fee_percent" type="number" min="0" max="20" step="0.01" required value="{{ old('gateway_fee_percent', data_get($pricing, 'gateway_fee_percent', 0)) }}">
                                <span>%</span>
                            </div>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label class="field-label required" for="min-campaign">{{ $isFa ? 'حداقل سفارش' : 'Minimum order' }}</label>
                            <div class="input-with-suffix">
                                <input class="input number" id="min-campaign" name="minimum_campaign_toman" type="number" min="1000" step="1000" required value="{{ old('minimum_campaign_toman', data_get($pricing, 'minimum_campaign_toman', 100000)) }}">
                                <span>{{ $isFa ? 'تومان' : 'Toman' }}</span>
                            </div>
                        </div>

                        <div class="field">
                            <label class="field-label required" for="quote-ttl">{{ $isFa ? 'اعتبار Quote' : 'Quote validity' }}</label>
                            <div class="input-with-suffix">
                                <input class="input number" id="quote-ttl" name="quote_ttl_minutes" type="number" min="1" max="1440" required value="{{ old('quote_ttl_minutes', data_get($pricing, 'quote_ttl_minutes', 15)) }}">
                                <span>{{ $isFa ? 'دقیقه' : 'min' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label class="field-label required" for="minimum-members">{{ $isFa ? 'حداقل عضو کانال هدف' : 'Minimum target members' }}</label>
                            <input class="input number" id="minimum-members" name="minimum_channel_members" type="number" min="1000" max="1000000000" required value="{{ old('minimum_channel_members', data_get($pricing, 'minimum_channel_members', 1000)) }}">
                        </div>

                        <div class="field">
                            <label class="field-label" for="conversion-margin">{{ $isFa ? 'حاشیه تبدیل' : 'Conversion margin' }}</label>
                            <div class="input-with-suffix">
                                <input class="input number" id="conversion-margin" name="conversion_margin_percent" type="number" min="0" max="25" step="0.01" value="{{ old('conversion_margin_percent', data_get($pricing, 'conversion_margin_percent', 0)) }}">
                                <span>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ $isFa ? 'نرخ زنده بازار' : 'Live market rates' }}</h2>
                        <p class="card-subtitle">{{ $isFa ? 'این نرخ‌ها قابل ویرایش نیستند و مستقیماً از API عمومی Exir خوانده می‌شوند.' : 'These rates are read-only and come directly from Exir public API.' }}</p>
                    </div>
                    <x-icon name="refresh" class="text-primary" />
                </div>

                <div class="metric-grid">
                    <div class="metric">
                        <div class="cluster-between">
                            <div class="metric-label">USDT / Toman</div>
                            <x-status-chip :value="$usdStateValue" :label="$usdStateLabel" />
                        </div>
                        <div class="metric-value number">{{ number_format(((float) data_get($rates, 'raw_usd_irr', 0)) / 10, 0) }}</div>
                        <div class="metric-delta">
                            {{ $isFa ? 'نرخ خام Exir' : 'Raw Exir rate' }} ·
                            {{ $formatRateTime(data_get($rates, 'usd_fetched_at')) }}
                        </div>
                    </div>

                    <div class="metric">
                        <div class="cluster-between">
                            <div class="metric-label">TON / USDT</div>
                            <x-status-chip :value="$tonStateValue" :label="$tonStateLabel" />
                        </div>
                        <div class="metric-value number">{{ number_format((float) data_get($rates, 'raw_ton_usd', 0), 6) }}</div>
                        <div class="metric-delta">
                            {{ $isFa ? 'نرخ خام Exir' : 'Raw Exir rate' }} ·
                            {{ $formatRateTime(data_get($rates, 'ton_fetched_at')) }}
                        </div>
                    </div>
                </div>

                <div class="notice" style="margin-top:16px">
                    <x-icon name="info" />
                    <div>
                        <strong>{{ $isFa ? 'Price Feed فعال است' : 'Price Feed is active' }}</strong>
                        <p>
                            {{ $isFa
                                ? 'هر بازار مستقل ۶۰ ثانیه کش می‌شود. اگر API یکی از بازارها پاسخ ندهد، آخرین نرخ موفق همان بازار نمایش داده و برای Quote استفاده می‌شود.'
                                : 'Each market is cached independently for 60 seconds. If one API request fails, that market uses its own last successful rate.' }}
                        </p>
                    </div>
                </div>

                <dl class="definition-list" style="margin-top:14px">
                    <div class="definition-row">
                        <dt>{{ $isFa ? 'نرخ مؤثر USDT بعد از مارک‌آپ' : 'Effective USDT rate after markup' }}</dt>
                        <dd class="number">{{ number_format(((float) data_get($rates, 'usd_irr', 0)) / 10, 0) }} {{ $isFa ? 'تومان' : 'Toman' }}</dd>
                    </div>
                    <div class="definition-row">
                        <dt>{{ $isFa ? 'نرخ مؤثر TON بعد از مارک‌آپ' : 'Effective TON rate after markup' }}</dt>
                        <dd class="number">{{ number_format((float) data_get($rates, 'ton_usd', 0), 6) }} USDT</dd>
                    </div>
                    <div class="definition-row">
                        <dt>{{ $isFa ? 'مارک‌آپ نرخ USDT' : 'USDT rate markup' }}</dt>
                        <dd class="number">{{ number_format((float) data_get($rates, 'markup_usd_percent', 0), 2) }}%</dd>
                    </div>
                    <div class="definition-row">
                        <dt>{{ $isFa ? 'مارک‌آپ نرخ TON' : 'TON rate markup' }}</dt>
                        <dd class="number">{{ number_format((float) data_get($rates, 'markup_ton_percent', 0), 2) }}%</dd>
                    </div>
                </dl>
            </article>
        </section>

        <aside class="stack">
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ $isFa ? 'وضعیت اتصالات' : 'Connection health' }}</h2>
                        <p class="card-subtitle">{{ $isFa ? 'کلیدها هرگز در پنل نمایش داده نمی‌شوند.' : 'Secrets are never rendered in the admin UI.' }}</p>
                    </div>
                </div>

                <div class="stack">
                    @forelse($gateways as $key => $gateway)
                        @php
                            $gatewayName = is_int($key) ? data_get($gateway, 'name', 'Gateway') : $key;
                            $connected = (bool) data_get($gateway, 'connected', data_get($gateway, 'configured', false));
                        @endphp
                        <div class="option-card">
                            <span class="quick-icon"><x-icon name="{{ $gatewayName === 'telegram' ? 'send' : ($gatewayName === 'nowpayments' ? 'globe' : 'card') }}" /></span>
                            <span class="option-card-copy">
                                <strong>{{ str((string) $gatewayName)->headline() }}</strong>
                                <small>{{ data_get($gateway, 'message', $connected ? ($isFa ? 'پیکربندی شده' : 'Configured') : ($isFa ? 'نیازمند تنظیم .env' : 'Needs .env configuration')) }}</small>
                            </span>
                            <x-status-chip :value="$connected ? 'verified' : 'pending'" :label="$connected ? ($isFa ? 'متصل' : 'Connected') : ($isFa ? 'ناقص' : 'Incomplete')" />
                        </div>
                    @empty
                        <div class="notice"><x-icon name="settings" /><p>{{ $isFa ? 'وضعیت درگاه‌ها توسط کنترلر ارسال می‌شود.' : 'Gateway health will appear when supplied by the controller.' }}</p></div>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">Callback / IPN</h2>
                        <p class="card-subtitle">{{ $isFa ? 'این URLها را عیناً در پنل درگاه قرار دهید.' : 'Paste these exact URLs into the gateway dashboards.' }}</p>
                    </div>
                </div>

                <div class="form-grid">
                    @forelse(collect($callbackUrls) as $name => $url)
                        <div class="field">
                            <label class="field-label">{{ str((string) $name)->headline() }}</label>
                            <div class="cluster">
                                <input class="input ltr" style="flex:1" readonly value="{{ $url }}">
                                <button class="icon-btn" type="button" data-copy="{{ $url }}" aria-label="{{ __('ui.actions.copy') }}"><x-icon name="copy" /></button>
                            </div>
                        </div>
                    @empty
                        <p class="muted">{{ $isFa ? 'آدرس‌ها پس از تنظیم APP_URL نمایش داده می‌شوند.' : 'URLs appear after APP_URL is configured.' }}</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    <div class="sticky-actions">
        <div>
            <strong>{{ $isFa ? 'ثبت تغییرات عملیاتی' : 'Save operational settings' }}</strong>
            <div class="subtle">{{ $isFa ? 'این اقدام در Audit Log ثبت می‌شود.' : 'This action is recorded in the audit log.' }}</div>
        </div>
        <button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('ui.actions.save') }}</button>
    </div>
</form>
@endsection
