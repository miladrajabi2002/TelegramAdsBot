<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delete a previously-sent bot message. Used to clean up the language
 * picker message after the user has chosen a language — keeping the chat
 * tidy instead of leaving a stale "Please choose your language" inline
 * keyboard sitting at the bottom of the conversation forever.
 */
class DeleteTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [5, 20];

    public function __construct(
        public int $chatId,
        public int $messageId,
    ) {}

    public function handle(TelegramBotClient $telegram): void
    {
        $telegram->deleteMessage($this->chatId, $this->messageId);
    }
}
