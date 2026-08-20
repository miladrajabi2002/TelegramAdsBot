<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\Setting;

class PricingService
{
    public function __construct(
        private readonly PriceFeedService $priceFeed,
    ) {
    }

    public function usdToIrr(float $usd): int
    {
        $rate = (float) $this->setting('usd_to_irr', config('ads-platform.usd_to_irr'));
        return (int) round($usd * $rate / $this->conversionFactor());
    }

    public function irrToUsd(int $irr): float
    {
        return round(
            $irr / max(0.000001, (float) $this->setting('usd_to_irr', config('ads-platform.usd_to_irr'))) * $this->conversionFactor(),
            2,
        );
    }

    /** @return array<string, int|float|string> */
    public function quote(int $mediaBudgetIrr): array
    {
        $rule = PricingRule::query()->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->latest('effective_from')->first();

        $markupBps = (int) ($rule?->service_markup_bps ?? config('ads-platform.service_markup_bps', 1500));
        $gatewayFeeBps = (int) ($rule?->gateway_fee_bps ?? 0);
        $minimumOrderIrr = (int) ($rule?->minimum_order_irr ?? config('ads-platform.minimum_order_irr', 1_000_000));
        $serviceFeeIrr = (int) ceil($mediaBudgetIrr * $markupBps / 10000);
        $gatewayFeeIrr = (int) ceil(($mediaBudgetIrr + $serviceFeeIrr) * $gatewayFeeBps / 10000);
        $totalIrr = $mediaBudgetIrr + $serviceFeeIrr + $gatewayFeeIrr;

        // When the live price feed is enabled, we override the rates from the
        // settings table with the most-recent cached feed response (which
        // already includes the configured markup). Otherwise we fall back to
        // the manual admin-set values in `settings`.
        $useAutomatic = (bool) config('ads-platform.automatic_exchange_rate', true);
        $feedMeta = $useAutomatic ? $this->priceFeed->currentRates() : null;

        $usdToIrr = $useAutomatic && $feedMeta
            ? (float) $feedMeta['usd_irr']
            : (float) $this->setting('usd_to_irr', config('ads-platform.usd_to_irr'));
        $gramToUsd = $useAutomatic && $feedMeta
            ? (float) $feedMeta['gram_usd']
            : (float) $this->setting('gram_to_usd', config('ads-platform.gram_to_usd'));

        $conversionMarginPercent = min(25, max(0, (float) $this->setting('conversion_margin_percent', 0)));
        $usd = $totalIrr / max(0.000001, $usdToIrr) * (1 + ($conversionMarginPercent / 100));

        $rateSource = $useAutomatic && $feedMeta
            ? ('live;'.$feedMeta['source'].';+'.$feedMeta['markup_percent'].'%')
            : 'admin_settings';

        return [
            'media_budget_irr' => $mediaBudgetIrr,
            'service_markup_bps' => $markupBps,
            'service_fee_irr' => $serviceFeeIrr,
            'gateway_fee_irr' => $gatewayFeeIrr,
            'total_irr' => $totalIrr,
            'minimum_order_irr' => $minimumOrderIrr,
            'usd_amount' => round($usd, 2),
            'gram_amount' => round($usd / max(0.000001, $gramToUsd), 9),
            'usd_to_irr_rate' => $usdToIrr,
            'gram_to_usd_rate' => $gramToUsd,
            'conversion_margin_bps' => (int) round($conversionMarginPercent * 100),
            'rate_source' => $rateSource,
        ];
    }

    public function quoteTtlMinutes(): int
    {
        // Campaign prices are intentionally short-lived. PaymentController
        // already blocks payment after quote_expires_at and the campaign page
        // exposes the refresh-quote action, so keeping this fixed at 15 minutes
        // guarantees the same rule for both newly-created and refreshed quotes.
        return 15;
    }

    public function minimumTargetMembers(): int
    {
        return max(1000, (int) $this->setting('minimum_channel_members', config('ads-platform.minimum_target_members', 1000)));
    }

    private function conversionFactor(): float
    {
        $percent = min(25, max(0, (float) $this->setting('conversion_margin_percent', 0)));
        return 1 + ($percent / 100);
    }

    private function setting(string $key, mixed $fallback): mixed
    {
        $setting = Setting::where('key', $key)->first();
        return data_get($setting?->value, 'value', $fallback);
    }
}
