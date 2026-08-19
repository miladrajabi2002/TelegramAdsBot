<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls live USD/IRR (Toman) and TON/USD (used as GRAM proxy) rates
 * exclusively from Exir.io's public v2 ticker endpoints, applies a
 * configurable buy-side markup, and serves the result with a 1-minute
 * cache that gracefully degrades to the LAST KNOWN GOOD price if the
 * upstream is unreachable.
 *
 * Endpoints (both GET):
 *   • https://api.exir.io/v2/ticker?symbol=ton-usdt
 *       → { last: <TON price in USDT> }
 *   • https://api.exir.io/v2/ticker?symbol=usdt-irt
 *       → { last: <1 USDT in IRT (toman)> }
 *
 * Markups (buy-side — the customer pays this much):
 *   • USD/IRR rate gets +X% (default 5%) via price_markup_usd_percent.
 *     A higher markup here means the customer pays MORE toman per USD.
 *   • TON/USD rate gets +Y% (default 2%) via price_markup_ton_percent.
 *     A higher markup here means the customer pays MORE USD per TON.
 *
 * Worked example (matches the spec):
 *   usdt-irt last = 190000  →  USD/IRR (marked) = 190000 * 1.05 * 10 = 1,995,000 IRR
 *   User wants to charge 500,000 toman (= 5,000,000 IRR):
 *     usd = 5,000,000 / 1,995,000 = 2.506  →  ≈ 2.5 USD  ✓
 *   ton-usdt last = 1.2  →  TON/USD (marked) = 1.2 * 1.02 = 1.224 USD/TON
 *     ton = 2.5 / 1.224 = 2.04  ←  spec says 1.224 ton because spec uses 2.5 / (1.2*1.02) = 2.04
 *     (the spec's 1.224 figure uses 2.5 USD as the dividend too — recompute: 2.5/(1.2*1.02)=2.04.
 *      Either way the math is the same: smaller-than-spot TON credited.)
 *
 * Cache behaviour:
 *   • "current" cache: 60 seconds (price_feed_ttl_seconds, default 60).
 *   • "last good" cache: 24 hours — survives fetch failures so the user
 *     always sees SOMETHING recent instead of the static fallback default.
 *   • On fetch failure: returns the last-good cached value if present,
 *     otherwise the static config default. Never returns null.
 */
class PriceFeedService
{
    private const CACHE_KEY_USD = 'pricefeed:usd_irr:v3';
    private const CACHE_KEY_TON = 'pricefeed:ton_usd:v3';
    private const CACHE_KEY_USD_LAST = 'pricefeed:usd_irr:last_good:v3';
    private const CACHE_KEY_TON_LAST = 'pricefeed:ton_usd:last_good:v3';
    private const CACHE_KEY_META = 'pricefeed:meta:v3';
    private const LAST_GOOD_TTL_SECONDS = 86400; // 24h

    /**
     * @return array{usd_irr: int, gram_usd: float, ton_usd: float, source: string, fetched_at: string, raw_usd_irr?: int, raw_ton_usd?: float, markup_usd_percent: float, markup_ton_percent: float}
     */
    public function currentRates(): array
    {
        $ttl = max(30, (int) config('ads-platform.price_feed_ttl_seconds', 60));

        $rawUsdIrr = $this->getCachedOrFetch(self::CACHE_KEY_USD, self::CACHE_KEY_USD_LAST, $ttl, fn () => $this->fetchUsdToman());
        $rawTonUsd = $this->getCachedOrFetch(self::CACHE_KEY_TON, self::CACHE_KEY_TON_LAST, $ttl, fn () => $this->fetchTonUsdt());

        $fallbackUsd = (int) config('ads-platform.usd_to_irr', 600000);
        $fallbackTon = (float) config('ads-platform.gram_to_usd', 3.25);

        $usdIrr = $rawUsdIrr ?? $fallbackUsd;
        $tonUsd = $rawTonUsd ?? $fallbackTon;

        $markupUsdPercent = (float) config('ads-platform.price_markup_usd_percent', 5.0);
        $markupTonPercent = (float) config('ads-platform.price_markup_ton_percent', 2.0);

        $markedUpUsdIrr = (int) round($usdIrr * (1 + ($markupUsdPercent / 100.0)));
        $markedUpTonUsd = round($tonUsd * (1 + ($markupTonPercent / 100.0)), 6);

        $usdSource = $rawUsdIrr !== null ? 'live' : 'default';
        $tonSource = $rawTonUsd !== null ? 'live' : 'default';

        return [
            'usd_irr' => $markedUpUsdIrr,
            'gram_usd' => $markedUpTonUsd, // backward-compat alias
            'ton_usd' => $markedUpTonUsd,
            'raw_usd_irr' => $rawUsdIrr,
            'raw_ton_usd' => $rawTonUsd,
            'source' => "usd:{$usdSource};ton:{$tonSource};exir.io/v2",
            'markup_usd_percent' => $markupUsdPercent,
            'markup_ton_percent' => $markupTonPercent,
            'markup_percent' => $markupUsdPercent, // backward-compat alias for PricingService
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Persist the current rates into the `settings` table so PricingService
     * can read them via its existing `setting('usd_to_irr', …)` lookup.
     * Called from a scheduled command every N minutes.
     */
    public function persistToSettings(): array
    {
        $rates = $this->currentRates();
        $now = now()->toIso8601String();

        Setting::updateOrCreate(
            ['key' => 'usd_to_irr'],
            ['value' => ['value' => $rates['usd_irr'], 'quoted_at' => $now, 'source' => $rates['source']], 'is_public' => true],
        );
        Setting::updateOrCreate(
            ['key' => 'gram_to_usd'],
            ['value' => ['value' => $rates['gram_usd'], 'quoted_at' => $now, 'source' => $rates['source']], 'is_public' => true],
        );

        return $rates;
    }

    /**
     * Fetch USD/Toman from Exir.io v2 (usdt-irt symbol) and convert to IRR (×10).
     * Returns null on any failure.
     */
    private function fetchUsdToman(): ?int
    {
        $url = (string) config('ads-platform.exir_usdt_irt_url', 'https://api.exir.io/v2/ticker?symbol=usdt-irt');
        try {
            $data = $this->http()->get($url)->json();
            $last = $data['last'] ?? null;
            if ($last === null) {
                Log::debug('PriceFeed: Exir usdt-irt empty response', ['body_sample' => is_string($data) ? substr($data, 0, 200) : null]);
                return null;
            }
            $toman = (float) preg_replace('/[^0-9.]/', '', (string) $last);
            if ($toman <= 0) {
                return null;
            }
            return (int) round($toman * 10); // toman → rial
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Exir usdt-irt fetch failed', ['exception' => $e->getMessage(), 'url' => $url]);
            return null;
        }
    }

    /**
     * Fetch TON/USD from Exir.io v2 (ton-usdt symbol).
     * Returns null on any failure.
     */
    private function fetchTonUsdt(): ?float
    {
        $url = (string) config('ads-platform.exir_ton_usdt_url', 'https://api.exir.io/v2/ticker?symbol=ton-usdt');
        try {
            $data = $this->http()->get($url)->json();
            $last = $data['last'] ?? null;
            if ($last === null) {
                Log::debug('PriceFeed: Exir ton-usdt empty response', ['body_sample' => is_string($data) ? substr($data, 0, 200) : null]);
                return null;
            }
            $val = (float) preg_replace('/[^0-9.]/', '', (string) $last);
            return $val > 0 ? $val : null;
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Exir ton-usdt fetch failed', ['exception' => $e->getMessage(), 'url' => $url]);
            return null;
        }
    }

    /**
     * Two-tier cache helper that returns the cached value if present, otherwise
     * tries to fetch fresh data. On fetch failure it falls back to the
     * "last good" cache (long TTL) so the user always sees a recent price
     * instead of the static config default.
     *
     * @param string   $currentKey   Short-TTL cache key (current price).
     * @param string   $lastGoodKey  Long-TTL cache key (most recent successful fetch).
     * @param int      $ttl          Short-TTL in seconds.
     * @param callable $fetcher      Returns fresh value or null on failure.
     * @return int|float|null
     */
    private function getCachedOrFetch(string $currentKey, string $lastGoodKey, int $ttl, callable $fetcher): mixed
    {
        $cached = Cache::get($currentKey);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $fetcher();
        if ($fresh !== null) {
            // Store both the short-TTL "current" value AND the long-TTL
            // "last good" value so future fetch failures can fall back to it.
            Cache::put($currentKey, $fresh, $ttl);
            Cache::put($lastGoodKey, $fresh, self::LAST_GOOD_TTL_SECONDS);
            return $fresh;
        }

        // Fetch failed — return the last good value if we have one,
        // otherwise null (the caller will fall back to the config default).
        $lastGood = Cache::get($lastGoodKey);
        return $lastGood;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(8)
            ->withHeaders(['Accept' => 'application/json'])
            ->retry(1, 200);
    }
}
