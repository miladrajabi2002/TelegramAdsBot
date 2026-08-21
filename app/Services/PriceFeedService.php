<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live USDT/IRT + TON/USDT price feed.
 *
 * Source:
 *   - Exir public API: /v2/ticker?symbol=usdt-irt
 *   - Exir public API: /v2/ticker?symbol=ton-usdt
 *
 * Behaviour:
 *   - each market is cached independently for exactly 60 seconds;
 *   - a failure in one market never prevents the other from refreshing;
 *   - every successful raw price is persisted as "last known good";
 *   - when an API call fails, the last known good value is returned;
 *   - static config is used only when the application has never obtained a
 *     successful price for that market.
 *
 * Internally the existing "usd_irr" / "gram_usd" names are kept for database
 * and schema compatibility. In the UI these are correctly presented as
 * USDT/IRT and TON/USDT.
 */
class PriceFeedService
{
    private const CURRENT_TTL_SECONDS = 60;

    private const CACHE_KEY_USDT_CURRENT = 'pricefeed:usdt_irr:current:v4';
    private const CACHE_KEY_TON_CURRENT = 'pricefeed:ton_usdt:current:v4';
    private const CACHE_KEY_USDT_LAST = 'pricefeed:usdt_irr:last_good:v4';
    private const CACHE_KEY_TON_LAST = 'pricefeed:ton_usdt:last_good:v4';

    private const SETTING_KEY_USDT_LAST = 'pricefeed_usdt_irr_raw_last_good';
    private const SETTING_KEY_TON_LAST = 'pricefeed_ton_usdt_raw_last_good';

    /**
     * @return array{
     *   usd_irr:int,
     *   gram_usd:float,
     *   ton_usd:float,
     *   raw_usd_irr:int,
     *   raw_ton_usd:float,
     *   source:string,
     *   fetched_at:string,
     *   usd_fetched_at:?string,
     *   ton_fetched_at:?string,
     *   usd_state:string,
     *   ton_state:string,
     *   markup_usd_percent:float,
     *   markup_ton_percent:float,
     *   markup_percent:float
     * }
     */
    public function currentRates(): array
    {
        $usd = $this->rate(
            currentKey: self::CACHE_KEY_USDT_CURRENT,
            lastGoodCacheKey: self::CACHE_KEY_USDT_LAST,
            lastGoodSettingKey: self::SETTING_KEY_USDT_LAST,
            fallback: (int) config('ads-platform.usd_to_irr', 600000),
            fetcher: fn (): ?int => $this->fetchUsdtIrr(),
            source: 'exir:usdt-irt',
        );

        $ton = $this->rate(
            currentKey: self::CACHE_KEY_TON_CURRENT,
            lastGoodCacheKey: self::CACHE_KEY_TON_LAST,
            lastGoodSettingKey: self::SETTING_KEY_TON_LAST,
            fallback: (float) config('ads-platform.gram_to_usd', 3.25),
            fetcher: fn (): ?float => $this->fetchTonUsdt(),
            source: 'exir:ton-usdt',
        );

        $rawUsdIrr = (int) round((float) $usd['value']);
        $rawTonUsd = (float) $ton['value'];

        $markupUsdPercent = max(0.0, min(50.0, (float) config('ads-platform.price_markup_usd_percent', 5.0)));
        $markupTonPercent = max(0.0, min(50.0, (float) config('ads-platform.price_markup_ton_percent', 2.0)));

        $markedUpUsdIrr = (int) round($rawUsdIrr * (1 + ($markupUsdPercent / 100.0)));
        $markedUpTonUsd = round($rawTonUsd * (1 + ($markupTonPercent / 100.0)), 6);

        $fetchedAt = collect([$usd['fetched_at'], $ton['fetched_at']])
            ->filter()
            ->sort()
            ->last() ?? now()->toIso8601String();

        return [
            'usd_irr' => $markedUpUsdIrr,
            'gram_usd' => $markedUpTonUsd, // backward-compatible schema alias
            'ton_usd' => $markedUpTonUsd,
            'raw_usd_irr' => $rawUsdIrr,
            'raw_ton_usd' => $rawTonUsd,
            'source' => sprintf(
                'exir.io/v2;usdt=%s;ton=%s',
                $usd['state'],
                $ton['state'],
            ),
            'fetched_at' => $fetchedAt,
            'usd_fetched_at' => $usd['fetched_at'],
            'ton_fetched_at' => $ton['fetched_at'],
            'usd_state' => $usd['state'],
            'ton_state' => $ton['state'],
            'markup_usd_percent' => $markupUsdPercent,
            'markup_ton_percent' => $markupTonPercent,
            'markup_percent' => $markupUsdPercent, // backward compatibility
        ];
    }

    /**
     * Persist effective live rates for legacy readers/diagnostics.
     *
     * PricingService itself reads currentRates() directly; these rows are kept
     * so admin diagnostics and old code never see stale manual values.
     */
    public function persistToSettings(): array
    {
        $rates = $this->currentRates();

        Setting::updateOrCreate(
            ['key' => 'usd_to_irr'],
            [
                'value' => [
                    'value' => $rates['usd_irr'],
                    'quoted_at' => $rates['usd_fetched_at'] ?? $rates['fetched_at'],
                    'source' => $rates['source'],
                ],
                'is_public' => true,
            ],
        );

        Setting::updateOrCreate(
            ['key' => 'gram_to_usd'],
            [
                'value' => [
                    'value' => $rates['gram_usd'],
                    'quoted_at' => $rates['ton_fetched_at'] ?? $rates['fetched_at'],
                    'source' => $rates['source'],
                ],
                'is_public' => true,
            ],
        );

        return $rates;
    }

    /**
     * Exir usdt-irt is quoted in IRT/Toman. Convert it to IRR for the existing
     * monetary schema by multiplying by 10.
     */
    private function fetchUsdtIrr(): ?int
    {
        $url = (string) config(
            'ads-platform.exir_usdt_irt_url',
            'https://api.exir.io/v2/ticker?symbol=usdt-irt',
        );

        try {
            $response = $this->http()->get($url);
            if (! $response->successful()) {
                Log::warning('PriceFeed: Exir USDT/IRT returned non-success status', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $last = data_get($response->json(), 'last');
            $toman = $this->numericPrice($last);
            if ($toman === null) {
                Log::warning('PriceFeed: Exir USDT/IRT response had no valid last price');
                return null;
            }

            return (int) round($toman * 10);
        } catch (\Throwable $e) {
            Log::warning('PriceFeed: Exir USDT/IRT fetch failed', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function fetchTonUsdt(): ?float
    {
        $url = (string) config(
            'ads-platform.exir_ton_usdt_url',
            'https://api.exir.io/v2/ticker?symbol=ton-usdt',
        );

        try {
            $response = $this->http()->get($url);
            if (! $response->successful()) {
                Log::warning('PriceFeed: Exir TON/USDT returned non-success status', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $last = data_get($response->json(), 'last');
            $price = $this->numericPrice($last);
            if ($price === null) {
                Log::warning('PriceFeed: Exir TON/USDT response had no valid last price');
                return null;
            }

            return $price;
        } catch (\Throwable $e) {
            Log::warning('PriceFeed: Exir TON/USDT fetch failed', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{value:int|float,fetched_at:?string,state:string,source:string}
     */
    private function rate(
        string $currentKey,
        string $lastGoodCacheKey,
        string $lastGoodSettingKey,
        int|float $fallback,
        callable $fetcher,
        string $source,
    ): array {
        $cached = Cache::get($currentKey);
        if ($this->validPayload($cached)) {
            return [
                ...$cached,
                'state' => 'cached',
            ];
        }

        $fresh = $fetcher();
        if (is_numeric($fresh) && (float) $fresh > 0) {
            $payload = [
                'value' => $fresh,
                'fetched_at' => now()->toIso8601String(),
                'state' => 'live',
                'source' => $source,
            ];

            Cache::put($currentKey, $payload, self::CURRENT_TTL_SECONDS);
            Cache::forever($lastGoodCacheKey, $payload);
            $this->persistLastGood($lastGoodSettingKey, $payload);

            return $payload;
        }

        $lastGood = Cache::get($lastGoodCacheKey);
        if (! $this->validPayload($lastGood)) {
            $lastGood = $this->loadLastGood($lastGoodSettingKey, $source);
            if ($this->validPayload($lastGood)) {
                Cache::forever($lastGoodCacheKey, $lastGood);
            }
        }

        if ($this->validPayload($lastGood)) {
            return [
                ...$lastGood,
                'state' => 'last_good',
            ];
        }

        return [
            'value' => $fallback,
            'fetched_at' => null,
            'state' => 'fallback',
            'source' => 'static_config',
        ];
    }

    /** @param mixed $payload */
    private function validPayload(mixed $payload): bool
    {
        return is_array($payload)
            && is_numeric($payload['value'] ?? null)
            && (float) $payload['value'] > 0;
    }

    /**
     * Persist last-good raw values to DB so they survive cache flushes,
     * restarts, deploys, and a long upstream outage.
     *
     * @param array{value:int|float,fetched_at:?string,state:string,source:string} $payload
     */
    private function persistLastGood(string $settingKey, array $payload): void
    {
        try {
            Setting::updateOrCreate(
                ['key' => $settingKey],
                [
                    'value' => [
                        'value' => $payload['value'],
                        'quoted_at' => $payload['fetched_at'],
                        'source' => $payload['source'],
                    ],
                    'is_public' => false,
                ],
            );
        } catch (\Throwable $e) {
            // Price delivery must not fail just because DB persistence of the
            // fallback snapshot failed. The normal cache still works.
            Log::warning('PriceFeed: could not persist last-known-good price', [
                'key' => $settingKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{value:int|float,fetched_at:?string,state:string,source:string}|null
     */
    private function loadLastGood(string $settingKey, string $source): ?array
    {
        try {
            $row = Setting::query()->where('key', $settingKey)->first();
            $value = data_get($row?->value, 'value');
            if (! is_numeric($value) || (float) $value <= 0) {
                return null;
            }

            return [
                'value' => $value + 0,
                'fetched_at' => data_get($row?->value, 'quoted_at'),
                'state' => 'last_good',
                'source' => (string) data_get($row?->value, 'source', $source),
            ];
        } catch (\Throwable $e) {
            Log::warning('PriceFeed: could not read persistent last-known-good price', [
                'key' => $settingKey,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function numericPrice(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', trim($value));
        if (! is_numeric($normalized)) {
            return null;
        }

        $price = (float) $normalized;

        return $price > 0 ? $price : null;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(6)
            ->connectTimeout(3)
            ->retry(1, 200, throw: false);
    }
}
