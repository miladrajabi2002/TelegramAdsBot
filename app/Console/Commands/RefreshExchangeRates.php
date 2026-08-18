<?php

namespace App\Console\Commands;

use App\Services\PriceFeedService;
use Illuminate\Console\Command;

class RefreshExchangeRates extends Command
{
    protected $signature = 'rates:refresh
                            {--force : Force refresh even when cache is still valid}
                            {--show : Print the rates after refresh and exit}';

    protected $description = 'Refresh USD/IRR and GRAM/USD rates from external price feeds and persist them to the settings table.';

    public function handle(PriceFeedService $feed): int
    {
        if ($this->option('force')) {
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
            (string) $rates['markup_percent'],
        ));
        $this->line(sprintf(
            '  GRAM/USD: %.6f (raw: %s)',
            $rates['gram_usd'],
            $rates['raw_gram_usd'] !== null ? (string) $rates['raw_gram_usd'] : 'fallback',
        ));
        $this->line('  Source: '.$rates['source']);
        $this->line('  Fetched at: '.$rates['fetched_at']);

        return self::SUCCESS;
    }
}
