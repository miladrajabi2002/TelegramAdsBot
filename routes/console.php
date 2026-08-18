<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram:webhook:set', function (\App\Services\Telegram\TelegramBotClient $telegram) {
    $url = rtrim((string) config('app.url'), '/').'/webhooks/telegram';
    $telegram->setWebhook($url);
    $this->info("Telegram webhook set to {$url}");
    $this->info('Allowed updates: message, callback_query');
})->purpose('Configure the Bot API webhook with its secret token (allows commands + inline-button callbacks)');

Schedule::call(function (): void {
    \App\Models\PaymentIntent::query()
        ->whereIn('status', ['created', 'pending'])
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired', 'updated_at' => now()]);
})->everyFiveMinutes()->name('expire-payment-intents')->withoutOverlapping();
