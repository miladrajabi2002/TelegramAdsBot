<?php

namespace App\Console\Commands;

use App\Services\PriceFeedService;
use Illuminate\Console\Command;

class RefreshExchangeRates extends Command
{
    protected $signature = 'rates:refresh
                            {--force : Force refresh even when cache is still valid}
                            {--show : Print the rates after refresh and exit}';

    protected $description = 'Refresh USD/IRR and TON/USD rates from Exir.io v2 and persist them to the settings table.';

    public function handle(PriceFeedService $feed): int
    {
        if ($this->option('force')) {
            // New v3 cache keys (PriceFeedService after the Exir.io refactor).
            cache()->forget('pricefeed:usd_irr:v3');
            cache()->forget('pricefeed:ton_usd:v3');
            cache()->forget('pricefeed:usd_irr:last_good:v3');
            cache()->forget('pricefeed:ton_usd:last_good:v3');
            // Legacy v2 keys (defensive — keeps --force working if anyone still
            // uses an old cached value).
            cache()->forget('pricefeed:usd_irr:v2');
            cache()->forget('pricefeed:gram_usd:v2');
            cache()->forget('pricefeed:meta:v2');
        }

        $rates = $feed->persistToSettings();

        $this->info('Exchange rates updated:');
        $this->line(sprintf(
            '  USD/IRR: %d IRR (raw: %s, markup: +%s%%)',
            $rates['usd_irr'],
            $rates['raw_usd_irr'] !== null ? (string) $rates['raw_usd_irr'] : 'fallback',
            (string) $rates['markup_usd_percent'],
        ));
        $this->line(sprintf(
            '  TON/USD: %.6f (raw: %s, markup: +%s%%)',
            $rates['ton_usd'],
            $rates['raw_ton_usd'] !== null ? (string) $rates['raw_ton_usd'] : 'fallback',
            (string) $rates['markup_ton_percent'],
        ));
        $this->line('  Source: '.$rates['source']);
        $this->line('  Fetched at: '.$rates['fetched_at']);

        return self::SUCCESS;
    }
}
