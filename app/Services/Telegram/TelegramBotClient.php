<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
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
            ], fn ($value) => $value !== null && $value !== ''));
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
            $result = $this->call('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => (int) $editMessageId,
                'reply_markup' => $replyMarkup,
            ]);

            return is_array($result) ? $result : [];
        }

        return [];
    }

    public function setWebhook(string $url): bool
    {
        $result = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        return (bool) $result;
    }

    public function getUserProfilePhotos(int $telegramUserId, int $limit = 1, int $offset = -1): ?array
    {
        try {
            $result = $this->call('getUserProfilePhotos', array_filter([
                'user_id' => $telegramUserId,
                'limit' => max(1, min(100, $limit)),
                'offset' => $offset >= 0 ? $offset : null,
            ], fn ($value) => $value !== null));

            return is_array($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

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
     * SECURITY: Telegram file URLs contain the bot token in their path.
     *
     * Older callers used this public method to return/store those URLs. Keep
     * the method for backward compatibility but deliberately return null so a
     * future call site cannot accidentally expose the bot token to a browser
     * or persist it in application data.
     *
     * Server-side downloads use internalFileDownloadUrl() instead.
     */
    public function fileDownloadUrl(string $filePath): ?string
    {
        return null;
    }

    public function getChat(int|string $chatId): ?array
    {
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

            $chatId = '@'.$normalized;
        }

        try {
            $result = $this->call('getChat', ['chat_id' => $chatId]);

            return is_array($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    public function getChatMemberCount(int|string $chatId): ?int
    {
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

            $chatId = '@'.$normalized;
        }

        try {
            $result = $this->call('getChatMemberCount', ['chat_id' => $chatId]);

            return is_int($result) ? $result : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        try {
            $result = $this->call('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            return $result === true || $result === 1;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Deprecated compatibility method.
     *
     * Returning a Telegram file URL would expose TELEGRAM_BOT_TOKEN, so this
     * method intentionally never returns a URL. Use a server-side byte proxy
     * such as AvatarController instead.
     */
    public function getLatestUserProfilePhotoUrl(int $telegramUserId): ?string
    {
        return null;
    }

    /**
     * Download the latest profile photo bytes entirely server-side.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function downloadLatestUserProfilePhoto(int $telegramUserId): ?array
    {
        $photos = $this->getUserProfilePhotos($telegramUserId, 1);
        if (! isset($photos['photos'][0]) || ! is_array($photos['photos'][0])) {
            return null;
        }

        $sizes = $photos['photos'][0];
        /** @var array<string, mixed>|false $largest */
        $largest = end($sizes);
        if (! is_array($largest)) {
            return null;
        }

        $fileId = $largest['file_id'] ?? null;
        if (! is_string($fileId) || $fileId === '') {
            return null;
        }

        $file = $this->getFile($fileId);
        $filePath = $file['file_path'] ?? null;
        if (! is_string($filePath) || $filePath === '') {
            return null;
        }

        $downloadUrl = $this->internalFileDownloadUrl($filePath);
        if ($downloadUrl === null) {
            return null;
        }

        try {
            $response = Http::timeout(8)->retry(1, 250)->get($downloadUrl);
            if (! $response->ok()) {
                return null;
            }

            $bytes = $response->body();
            if ($bytes === '' || strlen($bytes) > 8 * 1024 * 1024) {
                return null;
            }

            $mime = $response->header('Content-Type');
            if (! is_string($mime) || $mime === '') {
                $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    default => 'application/octet-stream',
                };
            }

            return ['bytes' => $bytes, 'mime' => $mime];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a token-bearing Telegram file URL for SERVER-SIDE use only.
     * This method must never be exposed through JSON, redirects, views, logs,
     * database columns, or other client-visible output.
     */
    private function internalFileDownloadUrl(string $filePath): ?string
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return null;
        }

        $filePath = ltrim($filePath, '/');

        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }

    private function call(string $method, array $payload): mixed
    {
        $response = $this->http()->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException((string) ($response['description'] ?? 'Telegram API error'));
        }

        return $response['result'] ?? null;
    }

    private function http(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()
            ->asJson()
            ->timeout(8)
            ->retry(1, 250);
    }
}
