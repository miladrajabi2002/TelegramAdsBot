<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SendBroadcastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [10, 30, 90, 300];

    public function __construct(public int $broadcastId) {}

    public function handle(TelegramBotClient $telegram): void
    {
        $broadcast = Broadcast::find($this->broadcastId);
        if (! $broadcast || ! in_array($broadcast->status, ['queued', 'sending'], true)) {
            return;
        }
        if ($broadcast->scheduled_at?->isFuture()) {
            self::dispatch($broadcast->getKey())->delay($broadcast->scheduled_at);

            return;
        }

        $broadcast->update(['status' => 'sending', 'started_at' => $broadcast->started_at ?? now()]);

        $recipients = $broadcast->recipients()->with('user')
            ->whereIn('status', ['queued', 'retry'])->where('attempts', '<', 4)
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->orderBy('id')->limit(10)->get();

        foreach ($recipients as $recipient) {
            try {
                $telegram->sendMessage($recipient->user->telegram_user_id, $broadcast->message, ['disable_web_page_preview' => true]);
                $recipient->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $recipient->attempts + 1, 'error' => null]);
            } catch (Throwable $exception) {
                $attempts = $recipient->attempts + 1;
                $recipient->update([
                    'status' => $attempts >= 4 ? 'failed' : 'retry',
                    'attempts' => $attempts,
                    'retry_at' => $attempts >= 4 ? null : now()->addMinutes($attempts * 2),
                    'error' => Str::limit($exception->getMessage(), 1000, ''),
                ]);
            }
        }

        if ($broadcast->recipients()->whereIn('status', ['queued', 'retry'])->exists()) {
            self::dispatch($broadcast->getKey())->delay(now()->addSeconds(2));
        } else {
            $broadcast->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}
