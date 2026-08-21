<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingRule;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\PriceFeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(PriceFeedService $priceFeed): View
    {
        $rule = PricingRule::where('is_active', true)->latest('effective_from')->first();
        $stored = Setting::all()->mapWithKeys(
            fn ($setting) => [$setting->key => data_get($setting->value, 'value', $setting->value)],
        );

        $pricing = [
            'markup_percent' => ($rule?->service_markup_bps ?? config('ads-platform.service_markup_bps', 1500)) / 100,
            'gateway_fee_percent' => ($rule?->gateway_fee_bps ?? 0) / 100,
            'minimum_campaign_toman' => intdiv(
                (int) ($rule?->minimum_order_irr ?? config('ads-platform.minimum_order_irr', 1_000_000)),
                10,
            ),
            'quote_ttl_minutes' => (int) $stored->get('quote_ttl_minutes', 15),
            'minimum_channel_members' => (int) $stored->get(
                'minimum_channel_members',
                config('ads-platform.minimum_target_members', 1000),
            ),
            'conversion_margin_percent' => (float) $stored->get('conversion_margin_percent', 0),
        ];

        // Read-only live rates for the admin UI. Each rate has its own
        // 60-second cache and independent last-known-good fallback.
        $liveRates = $priceFeed->currentRates();

        $settings = $pricing;
        $zarinPayEnabled = (bool) config('services.zarinpay.enabled')
            || (app()->isLocal() && (bool) config('services.zarinpay.mock'));
        $nowPaymentsEnabled = (bool) config('services.nowpayments.enabled');

        $gatewayStatus = [
            'telegram' => [
                'configured' => filled(config('services.telegram.bot_token'))
                    && filled(config('services.telegram.webhook_secret')),
                'message' => 'Bot token + webhook secret',
            ],
            'zarinpay' => [
                'configured' => $zarinPayEnabled
                    && (config('services.zarinpay.mock') || filled(config('services.zarinpay.access_token'))),
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

        return view('admin.settings.index', compact(
            'pricing',
            'settings',
            'liveRates',
            'gatewayStatus',
            'callbackUrls',
        ));
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'gateway_fee_percent' => ['required', 'numeric', 'min:0', 'max:20'],
            'minimum_campaign_toman' => ['required', 'integer', 'min:1000'],
            'quote_ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'minimum_channel_members' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'conversion_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:25'],
        ]);

        DB::transaction(function () use ($data): void {
            PricingRule::where('is_active', true)
                ->update(['is_active' => false, 'effective_to' => now()]);

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
                'conversion_margin_percent' => (float) ($data['conversion_margin_percent'] ?? 0),
            ];

            foreach ($values as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => [
                            'value' => $value,
                            'quoted_at' => now()->toIso8601String(),
                        ],
                        'is_public' => false,
                        'updated_by' => auth('admin')->id(),
                    ],
                );
            }
        });

        $audit->log('settings.pricing_updated', auth('admin')->user(), after: [
            'markup_percent' => $data['markup_percent'],
            'gateway_fee_percent' => $data['gateway_fee_percent'],
            'minimum_campaign_toman' => $data['minimum_campaign_toman'],
            'quote_ttl_minutes' => $data['quote_ttl_minutes'],
            'minimum_channel_members' => $data['minimum_channel_members'],
            'conversion_margin_percent' => $data['conversion_margin_percent'] ?? 0,
        ]);

        return back()->with(
            'success',
            'تنظیمات ذخیره شد. نرخ USDT و TON به‌صورت خودکار از Price Feed دریافت می‌شوند.',
        );
    }
}
