<?php

namespace App\Console\Commands;

use App\Services\PriceFeedService;
use Illuminate\Console\Command;

class RefreshExchangeRates extends Command
{
    protected $signature = 'rates:refresh
                            {--force : Ignore the 60-second current cache and try the APIs now}
                            {--show : Print the rates after refresh and exit}';

    protected $description = 'Refresh USDT/IRT and TON/USDT rates from Exir public API and persist effective rates.';

    public function handle(PriceFeedService $feed): int
    {
        if ($this->option('force')) {
            // Only clear the short-lived current cache. Never erase last-known
            // good values: --force must still fall back safely if Exir is down.
            cache()->forget('pricefeed:usdt_irr:current:v4');
            cache()->forget('pricefeed:ton_usdt:current:v4');
        }

        $rates = $feed->persistToSettings();

        $this->info('Exchange rates updated:');
        $this->line(sprintf(
            '  USDT/IRT: %s Toman (state: %s, markup: +%s%%)',
            number_format(((int) $rates['raw_usd_irr']) / 10, 0, '.', ''),
            $rates['usd_state'],
            (string) $rates['markup_usd_percent'],
        ));
        $this->line(sprintf(
            '  TON/USDT: %.6f (state: %s, markup: +%s%%)',
            $rates['raw_ton_usd'],
            $rates['ton_state'],
            (string) $rates['markup_ton_percent'],
        ));
        $this->line('  Source: '.$rates['source']);
        $this->line('  Effective USDT/IRT: '.number_format($rates['usd_irr'] / 10, 0, '.', '').' Toman');
        $this->line('  Effective TON/USDT: '.number_format($rates['ton_usd'], 6, '.', ''));

        return self::SUCCESS;
    }
}
