<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        // When the caller passes a callback_query_id we need TWO Telegram API
        // calls in one shot: answerCallbackQuery (clears the spinner) + an
        // optional message edit/send. We co-locate them so the queue worker
        // only has to dispatch a single SendTelegramMessage job.
        $callbackQueryId = $options['callback_query_id'] ?? null;
        $answerText = $options['answer_text'] ?? null;
        $answerShowAlert = $options['answer_show_alert'] ?? false;
        $editMessageId = $options['edit_message_id'] ?? null;
        $replyMarkup = $options['reply_markup'] ?? null;
        $disablePreview = array_key_exists('disable_web_page_preview', $options)
            ? (bool) $options['disable_web_page_preview']
            : true;

        // Strip our private options before forwarding to Telegram.
        unset(
            $options['callback_query_id'],
            $options['answer_text'],
            $options['answer_show_alert'],
            $options['edit_message_id'],
        );

        if ($callbackQueryId !== null) {
            $this->call('answerCallbackQuery', array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $answerText,
                'show_alert' => $answerShowAlert,
            ], fn ($v) => $v !== null && $v !== ''));
        }

        if ($text !== '') {
            $payload['disable_web_page_preview'] = $disablePreview;
            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            return $this->call('sendMessage', $payload);
        }

        if ($replyMarkup !== null && $editMessageId !== null) {
            // No new text — just refresh the inline keyboard on the original message.
            return $this->call('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => (int) $editMessageId,
                'reply_markup' => $replyMarkup,
            ]);
        }

        return [];
    }

    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
            // We need both message (commands, contact shares) AND callback_query
            // (inline-button presses). Drop pending updates is OFF — we never
            // want to lose a user action.
            'allowed_updates' => ['message', 'callback_query'],
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
