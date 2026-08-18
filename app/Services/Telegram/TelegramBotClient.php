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
     * Fetch the user's profile photos from Telegram.
     *
     * Returns the raw `UserProfilePhotos` object:
     *   [
     *     'total_count' => int,
     *     'photos'      => [[ PhotoSize, PhotoSize, PhotoSize ], ...],
     *   ]
     * Returns null when the user has no photos or the call fails.
     *
     * Each `PhotoSize` is `['file_id' => string, 'file_unique_id' => string, 'width' => int, 'height' => int]`.
     * The last item of each inner array is the largest size.
     */
    public function getUserProfilePhotos(int $telegramUserId, int $limit = 1, int $offset = -1): ?array
    {
        try {
            $result = $this->call('getUserProfilePhotos', array_filter([
                'user_id' => $telegramUserId,
                'limit' => max(1, min(100, $limit)),
                'offset' => $offset >= 0 ? $offset : null,
            ], fn ($v) => $v !== null));

            return is_array($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Get basic info about a file on Telegram's servers (needed to download a profile photo).
     *
     * Returns `['file_id' => string, 'file_unique_id' => string, 'file_size' => int, 'file_path' => string]`
     * or null on failure. The `file_path` is then used to build the download URL:
     *   https://api.telegram.org/file/bot{TOKEN}/{file_path}
     */
    public function getFile(string $fileId): ?array
    {
        try {
            $result = $this->call('getFile', ['file_id' => $fileId]);

            return is_array($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Build the public download URL for a file_path returned by getFile().
     * Returns null when the bot token is not configured.
     */
    public function fileDownloadUrl(string $filePath): ?string
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return null;
        }

        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }

    /**
     * Resolve a public channel/supergroup/bot by username or numeric chat_id.
     *
     * Accepts either "@channelname", "channelname", "https://t.me/channelname",
     * or a numeric chat_id (e.g. "-1001234567890").
     *
     * Returns the Chat object on success, null when not found or not public.
     * For private channels (which require the bot to be a member) the call will
     * also return null because Telegram replies 400 BAD_REQUEST.
     */
    public function getChat(int|string $chatId): ?array
    {
        // Normalize username-like inputs to the form Telegram expects (without leading @).
        if (is_string($chatId) && ! ctype_digit(ltrim($chatId, '-'))) {
            $normalized = ltrim($chatId, '@');
            if (str_starts_with($normalized, 'https://t.me/')) {
                $normalized = substr($normalized, strlen('https://t.me/'));
            }
            if (str_starts_with($normalized, 'http://t.me/')) {
                $normalized = substr($normalized, strlen('http://t.me/'));
            }
            $normalized = trim($normalized, '/');
            if (! preg_match('/^[A-Za-z0-9_]{4,64}$/', $normalized) && ! ctype_digit(ltrim($normalized, '-'))) {
                return null;
            }
            $chatId = '@' . $normalized;
        }

        try {
            $result = $this->call('getChat', ['chat_id' => $chatId]);

            return is_array($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Get the latest (largest) profile photo URL for a Telegram user.
     *
     * Convenience wrapper that:
     *   1. Calls getUserProfilePhotos(limit=1).
     *   2. Picks the largest size from the most recent photo set.
     *   3. Calls getFile() to get the file_path.
     *   4. Builds the public download URL.
     *
     * Returns null when the user has no photo or any step fails.
     */
    public function getLatestUserProfilePhotoUrl(int $telegramUserId): ?string
    {
        $photos = $this->getUserProfilePhotos($telegramUserId, 1);
        if (! isset($photos['photos'][0]) || ! is_array($photos['photos'][0])) {
            return null;
        }

        $sizes = $photos['photos'][0];
        /** @var array<string, mixed> $largest */
        $largest = end($sizes);
        $fileId = $largest['file_id'] ?? null;
        if (! is_string($fileId) || $fileId === '') {
            return null;
        }

        $file = $this->getFile($fileId);
        $filePath = $file['file_path'] ?? null;
        if (! is_string($filePath) || $filePath === '') {
            return null;
        }

        return $this->fileDownloadUrl($filePath);
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

        // 8s timeout with a single retry (~250ms back-off) — keeps the
        // median request under 1s on healthy networks while tolerating a
        // transient flake. The previous 15s/2-retry setup was responsible
        // for the ~2s "feels slow" symptom on every bot interaction.
        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()->asJson()->timeout(8)->retry(1, 250);
    }
}
