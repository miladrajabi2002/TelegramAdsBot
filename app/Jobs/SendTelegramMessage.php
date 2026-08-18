<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [10, 30, 120, 300];

    /** @param array<string, mixed> $options */
    public function __construct(
        public int $chatId,
        public string $message,
        public array $options = [],
    ) {}

    public function handle(TelegramBotClient $telegram): void
    {
        $telegram->sendMessage($this->chatId, $this->message, $this->options);
    }
}
