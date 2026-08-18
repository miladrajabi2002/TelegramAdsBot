<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingRule;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $rule = PricingRule::where('is_active', true)->latest('effective_from')->first();
        $stored = Setting::all()->mapWithKeys(fn ($setting) => [$setting->key => data_get($setting->value, 'value', $setting->value)]);
        $pricing = [
            'markup_percent' => ($rule?->service_markup_bps ?? config('ads-platform.service_markup_bps', 1500)) / 100,
            'gateway_fee_percent' => ($rule?->gateway_fee_bps ?? 0) / 100,
            'minimum_campaign_toman' => intdiv((int) ($rule?->minimum_order_irr ?? config('ads-platform.minimum_order_irr', 1_000_000)), 10),
            'quote_ttl_minutes' => (int) $stored->get('quote_ttl_minutes', 30),
            'minimum_channel_members' => (int) $stored->get('minimum_channel_members', config('ads-platform.minimum_target_members', 1000)),
            'usd_to_toman_rate' => (float) $stored->get('usd_to_irr', config('ads-platform.usd_to_irr', 600000)) / 10,
            'gram_to_usd' => (float) $stored->get('gram_to_usd', config('ads-platform.gram_to_usd', 3.25)),
            'conversion_margin_percent' => (float) $stored->get('conversion_margin_percent', 0),
            'automatic_exchange_rate' => (bool) $stored->get('automatic_exchange_rate', false),
        ];
        $settings = $pricing;
        $zarinPayEnabled = (bool) config('services.zarinpay.enabled')
            || (app()->isLocal() && (bool) config('services.zarinpay.mock'));
        $nowPaymentsEnabled = (bool) config('services.nowpayments.enabled');
        $gatewayStatus = [
            'telegram' => [
                'configured' => filled(config('services.telegram.bot_token')) && filled(config('services.telegram.webhook_secret')),
                'message' => 'Bot token + webhook secret',
            ],
            'zarinpay' => [
                'configured' => $zarinPayEnabled && (config('services.zarinpay.mock') || filled(config('services.zarinpay.access_token'))),
                'message' => ! $zarinPayEnabled
                    ? 'Disabled by ZARINPAY_ENABLED'
                    : (config('services.zarinpay.mock') ? 'Local mock mode' : 'Server-side API token'),
            ],
            'nowpayments' => [
                'configured' => $nowPaymentsEnabled
                    && filled(config('services.nowpayments.api_key'))
                    && filled(config('services.nowpayments.ipn_secret')),
                'message' => $nowPaymentsEnabled ? 'API key + IPN secret' : 'Disabled by NOWPAYMENTS_ENABLED',
            ],
        ];
        $callbackUrls = [
            'zarinpay_callback' => route('payments.zarinpay.callback'),
            'nowpayments_ipn' => route('webhooks.nowpayments'),
            'telegram_webhook' => route('webhooks.telegram'),
        ];

        return view('admin.settings.index', compact('pricing', 'settings', 'gatewayStatus', 'callbackUrls'));
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'gateway_fee_percent' => ['required', 'numeric', 'min:0', 'max:20'],
            'minimum_campaign_toman' => ['required', 'integer', 'min:1000'],
            'quote_ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'minimum_channel_members' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'usd_to_toman_rate' => ['nullable', 'numeric', 'min:1'],
            'gram_to_usd' => ['nullable', 'numeric', 'min:0.000001'],
            'conversion_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:25'],
            'automatic_exchange_rate' => ['nullable', 'boolean'],
        ]);

        $currentRule = PricingRule::where('is_active', true)->latest('effective_from')->first();
        $currentUsdIrr = (float) data_get(Setting::where('key', 'usd_to_irr')->first()?->value, 'value', config('ads-platform.usd_to_irr', 600000));
        $currentGramUsd = (float) data_get(Setting::where('key', 'gram_to_usd')->first()?->value, 'value', config('ads-platform.gram_to_usd', 3.25));

        DB::transaction(function () use ($request, $data, $currentRule, $currentUsdIrr, $currentGramUsd): void {
            PricingRule::where('is_active', true)->update(['is_active' => false, 'effective_to' => now()]);
            PricingRule::create([
                'service_markup_bps' => (int) round($data['markup_percent'] * 100),
                'gateway_fee_bps' => (int) round($data['gateway_fee_percent'] * 100),
                'minimum_order_irr' => (int) $data['minimum_campaign_toman'] * 10,
                'is_active' => true,
                'effective_from' => now(),
                'created_by' => auth('admin')->id(),
            ]);

            $values = [
                'quote_ttl_minutes' => (int) $data['quote_ttl_minutes'],
                'minimum_channel_members' => (int) $data['minimum_channel_members'],
                'usd_to_irr' => isset($data['usd_to_toman_rate']) ? (float) $data['usd_to_toman_rate'] * 10 : $currentUsdIrr,
                'gram_to_usd' => isset($data['gram_to_usd']) ? (float) $data['gram_to_usd'] : $currentGramUsd,
                'conversion_margin_percent' => (float) ($data['conversion_margin_percent'] ?? 0),
                'automatic_exchange_rate' => $request->boolean('automatic_exchange_rate'),
            ];
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(['key' => $key], [
                    'value' => ['value' => $value, 'quoted_at' => now()->toIso8601String()],
                    'is_public' => in_array($key, ['usd_to_irr', 'gram_to_usd'], true),
                    'updated_by' => auth('admin')->id(),
                ]);
            }
        });

        $audit->log('settings.pricing_updated', auth('admin')->user(), after: [
            'markup_percent' => $data['markup_percent'],
            'gateway_fee_percent' => $data['gateway_fee_percent'],
            'minimum_campaign_toman' => $data['minimum_campaign_toman'],
            'quote_ttl_minutes' => $data['quote_ttl_minutes'],
            'minimum_channel_members' => $data['minimum_channel_members'],
        ]);

        return back()->with('success', 'نسخه جدید قیمت‌گذاری فعال شد؛ سفارش‌ها و Quoteهای قبلی تغییر نکردند.');
    }
}
