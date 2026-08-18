<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            ...$options,
        ]);
    }

    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message'],
        ]);
    }

    /** @return array<string, mixed> */
    private function call(string $method, array $payload): array
    {
        $response = $this->http()->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException((string) ($response['description'] ?? 'Telegram API error'));
        }

        return $response['result'] ?? [];
    }

    private function http(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()->asJson()->timeout(15)->retry(2, 250);
    }
}
