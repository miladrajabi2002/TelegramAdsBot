<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    /**
     * Send a message (or answer a callback query + send/edit a message in
     * one shot via private options in $options).
     *
     * Private $options keys (consumed here, NOT forwarded to Telegram):
     *   - callback_query_id  string|null  If set, answerCallbackQuery is fired first.
     *   - answer_text        string|null The toast text for the callback answer.
     *   - answer_show_alert  bool        Whether to show the answer as a modal alert.
     *   - edit_message_id    int|null    When text is empty but reply_markup is set,
     *                                    editMessageReplyMarkup is called on this message.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> Telegram Message object (or [] when no message was sent).
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

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
            // answerCallbackQuery returns bool true on success, so we ignore the result.
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

            $result = $this->call('sendMessage', $payload);

            return is_array($result) ? $result : [];
        }

        if ($replyMarkup !== null && $editMessageId !== null) {
            // editMessageReplyMarkup also returns the edited Message (or true on some clients).
            $result = $this->call('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => (int) $editMessageId,
                'reply_markup' => $replyMarkup,
            ]);

            return is_array($result) ? $result : [];
        }

        return [];
    }

    /**
     * Register the webhook URL with Telegram.
     *
     * Telegram returns `{ok: true, result: true}` for setWebhook — `result`
     * is a boolean, NOT an array. So the return type is mixed (we cast to bool).
     */
    public function setWebhook(string $url): bool
    {
        $result = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
            // We need both message (commands, contact shares) AND callback_query
            // (inline-button presses). Drop pending updates is OFF — we never
            // want to lose a user action.
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        return (bool) $result;
    }

    /**
     * Low-level call to the Telegram Bot API.
     *
     * Telegram's API has three possible `result` shapes:
     *   - Object/array  (sendMessage, getUpdates, …)
     *   - Boolean true  (setWebhook, answerCallbackQuery, deleteWebhook, …)
     *   - String/int    (getFile returns a file_path string, etc.)
     *
     * We return `mixed` here and let callers cast/validate as needed.
     *
     * @param array<string, mixed> $payload
     * @return mixed Raw `result` field from Telegram's response.
     */
    private function call(string $method, array $payload): mixed
    {
        $response = $this->http()->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException((string) ($response['description'] ?? 'Telegram API error'));
        }

        // Don't default to [] — that hides "true" results behind an array cast.
        // Callers that expect an array should check with is_array().
        return $response['result'] ?? null;
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
