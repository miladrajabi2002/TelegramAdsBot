<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls live USD/IRR and GRAM/USD rates from multiple external sources
 * with graceful fallback, and adds a configurable markup on the USD rate
 * so the platform always quotes a "buy" rate (market + premium).
 *
 * Sources tried in order:
 *   1. TGJU live (https://call4.tgju.org/ajax.json) — primary USD/IRR.
 *   2. Bonbast API (https://bonbast.com/api/rates) — backup USD/IRR.
 *   3. Navasan API (https://navasan.net/api/v1/api.php) — backup USD/IRR.
 *   4. Static default from config('ads-platform.usd_to_irr').
 *
 * For GRAM/USD (Telegram's in-app currency), the public spot reference is
 * no longer published anywhere because the GRAM project was wound down
 * pre-launch. We therefore use TON (the closest successor on the same
 * blockchain) as a price proxy, with these sources:
 *   1. CoinGecko (https://api.coingecko.com/api/v3/.../toncoin) — primary.
 *   2. CoinCap (https://api.coincap.io/v2/assets/ton) — backup.
 *   3. Static default from config('ads-platform.gram_to_usd').
 *
 * All results are cached (default 5 minutes for USD/IRR, 10 minutes for
 * GRAM/USD) so we don't hammer the upstream providers.
 *
 * The +X% markup is configured via `ads-platform.price_markup_percent`
 * (default 4.0 — the platform adds 4% to every quoted USD rate so the
 * displayed "dollar price" matches what the customer will actually pay).
 */
class PriceFeedService
{
    private const CACHE_KEY_USD = 'pricefeed:usd_irr:v2';
    private const CACHE_KEY_GRAM = 'pricefeed:gram_usd:v2';
    private const CACHE_KEY_META = 'pricefeed:meta:v2';

    /**
     * @return array{usd_irr: int, gram_usd: float, source: string, fetched_at: string, raw_usd_irr?: int, raw_gram_usd?: float}
     */
    public function currentRates(): array
    {
        $ttl = (int) config('ads-platform.price_feed_ttl_seconds', 300);

        $rawUsdIrr = Cache::remember(self::CACHE_KEY_USD, $ttl, fn (): ?int => $this->fetchRawUsdIrr());
        $rawGramUsd = Cache::remember(self::CACHE_KEY_GRAM, $ttl * 2, fn (): ?float => $this->fetchRawGramUsd());

        $fallbackUsd = (int) config('ads-platform.usd_to_irr', 600000);
        $fallbackGram = (float) config('ads-platform.gram_to_usd', 3.25);

        $usdIrr = $rawUsdIrr ?? $fallbackUsd;
        $gramUsd = $rawGramUsd ?? $fallbackGram;

        $markupPercent = (float) config('ads-platform.price_markup_percent', 4.0);
        $markupMultiplier = 1 + ($markupPercent / 100.0);

        // Apply markup to USD/IRR (the customer pays this much IRR per 1 USD).
        $markedUpUsdIrr = (int) round($usdIrr * $markupMultiplier);
        // Apply markup to GRAM/USD (the customer pays this much USD per 1 GRAM).
        $markedUpGramUsd = round($gramUsd * $markupMultiplier, 6);

        $usdSource = $rawUsdIrr !== null ? 'live' : 'default';
        $gramSource = $rawGramUsd !== null ? 'live' : 'default';

        return [
            'usd_irr' => $markedUpUsdIrr,
            'gram_usd' => $markedUpGramUsd,
            'raw_usd_irr' => $rawUsdIrr,
            'raw_gram_usd' => $rawGramUsd,
            'source' => "usd:{$usdSource};gram:{$gramSource}",
            'markup_percent' => $markupPercent,
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
     * @return int Raw IRR per 1 USD (no markup), or null if every source failed.
     */
    private function fetchRawUsdIrr(): ?int
    {
        // Primary: TGJU
        $value = $this->fetchFromTgju();
        if ($value !== null) {
            return $value;
        }

        // Backup 1: Bonbast (public mirror at bonbast.com)
        $value = $this->fetchFromBonbast();
        if ($value !== null) {
            return $value;
        }

        // Backup 2: Navasan (free public endpoint)
        $value = $this->fetchFromNavasan();
        if ($value !== null) {
            return $value;
        }

        // Backup 3: exir.io (Iranian crypto exchange; Toman/USDT ticker)
        $value = $this->fetchFromExir();
        if ($value !== null) {
            return $value;
        }

        return null;
    }

    private function fetchFromTgju(): ?int
    {
        $url = (string) config('ads-platform.tgju_url', 'https://call4.tgju.org/ajax.json');
        try {
            $body = $this->http()->get($url)->body();
            $data = json_decode($body, true);
            if (! is_array($data)) {
                return null;
            }
            // TGJU returns nested arrays. Look for the USD Toman rate.
            // Format: data.usd.price.value  OR  data.usd_free.price.value
            $usd = $data['usd']['price']['value'] ?? null;
            if (! is_string($usd) && ! is_numeric($usd)) {
                // Try alternative shape (different TGJU API versions)
                foreach ($data as $key => $row) {
                    if (is_array($row) && str_starts_with((string) $key, 'usd')) {
                        $usd = $row['price']['value'] ?? ($row['p'] ?? null);
                        if ($usd !== null) break;
                    }
                }
            }
            if ($usd === null) return null;
            // TGJU reports in Toman — convert to IRR (×10).
            $toman = (float) preg_replace('/[^0-9.]/', '', (string) $usd);
            if ($toman <= 0) return null;
            return (int) round($toman * 10);
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: TGJU fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchFromBonbast(): ?int
    {
        $url = (string) config('ads-platform.bonbast_url', 'https://bonbast.com/api/rates');
        try {
            $data = $this->http()->get($url, ['format' => 'json'])->json();
            $usd = $data['usd']['sell'] ?? $data['usd']['buy'] ?? null;
            if ($usd === null) return null;
            $toman = (float) preg_replace('/[^0-9.]/', '', (string) $usd);
            if ($toman <= 0) return null;
            return (int) round($toman * 10);
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Bonbast fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchFromNavasan(): ?int
    {
        $url = (string) config('ads-platform.navasan_url', 'https://navasan.net/api/v1/api.php');
        $apiKey = (string) config('ads-platform.navasan_api_key', '');
        try {
            $params = ['currency' => 'usd'];
            if ($apiKey !== '') $params['api_key'] = $apiKey;
            $data = $this->http()->get($url, $params)->json();
            $usd = $data['usd']['value'] ?? $data['usd']['p'] ?? null;
            if ($usd === null) return null;
            $toman = (float) preg_replace('/[^0-9.]/', '', (string) $usd);
            if ($toman <= 0) return null;
            return (int) round($toman * 10);
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Navasan fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchFromExir(): ?int
    {
        // exir.io exposes a public ticker for usdt-irr.
        $url = 'https://api.exir.io/v1/ticker?symbol=usdt-irr';
        try {
            $data = $this->http()->get($url)->json();
            $last = $data['lastPrice'] ?? null;
            if ($last === null) return null;
            $toman = (float) $last;
            if ($toman <= 0) return null;
            return (int) round($toman * 10);
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Exir fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @return float Raw USD per 1 GRAM (no markup), or null if every source failed.
     */
    private function fetchRawGramUsd(): ?float
    {
        // Primary: CoinGecko TON
        $value = $this->fetchGramFromCoinGecko();
        if ($value !== null) {
            return $value;
        }

        // Backup 1: CoinCap TON
        $value = $this->fetchGramFromCoinCap();
        if ($value !== null) {
            return $value;
        }

        // Backup 2: Binance TON/USDT (treats TON = GRAM proxy)
        $value = $this->fetchGramFromBinance();
        if ($value !== null) {
            return $value;
        }

        return null;
    }

    private function fetchGramFromCoinGecko(): ?float
    {
        $url = 'https://api.coingecko.com/api/v3/simple/price';
        try {
            $data = $this->http()->get($url, [
                'ids' => 'the-open-network',
                'vs_currencies' => 'usd',
            ])->json();
            $value = $data['the-open-network']['usd'] ?? null;
            if ($value === null) return null;
            $val = (float) $value;
            return $val > 0 ? $val : null;
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: CoinGecko fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchGramFromCoinCap(): ?float
    {
        $url = 'https://api.coincap.io/v2/assets/ton';
        try {
            $data = $this->http()->get($url)->json();
            $value = $data['data']['priceUsd'] ?? null;
            if ($value === null) return null;
            $val = (float) $value;
            return $val > 0 ? $val : null;
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: CoinCap fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchGramFromBinance(): ?float
    {
        $url = 'https://api.binance.com/api/v3/ticker/price';
        try {
            $data = $this->http()->get($url, ['symbol' => 'TONUSDT'])->json();
            $value = $data['price'] ?? null;
            if ($value === null) return null;
            $val = (float) $value;
            return $val > 0 ? $val : null;
        } catch (\Throwable $e) {
            Log::debug('PriceFeed: Binance fetch failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout(8)
            ->withHeaders(['Accept' => 'application/json'])
            ->retry(1, 200);
    }
}
