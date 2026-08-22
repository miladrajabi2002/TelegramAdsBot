<?php

namespace App\Jobs;

use App\Models\User;
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
        $options = $this->options;

        // Plain push-style notifications should always offer a reliable way
        // back into the Mini App. Messages that already provide their own
        // keyboard (main menu, language picker, MiniAppNotifier deep links,
        // etc.) are left completely untouched.
        if ($this->message !== '' && ! array_key_exists('reply_markup', $options)) {
            $user = User::query()->where('telegram_user_id', $this->chatId)->first();

            if ($user) {
                // Legacy users may pre-date the magic_token column/default.
                // Generate a token only when it is missing; never rotate a
                // healthy token here, otherwise buttons in older notifications
                // would immediately become invalid.
                if (empty($user->magic_token)) {
                    $user->rotateMagicToken();
                }

                $token = (string) $user->magic_token;
                if ($token !== '') {
                    $appUrl = rtrim((string) config('app.url'), '/').'/app?t='.urlencode($token);
                    $isFa = $user->locale === 'fa';

                    $options['reply_markup'] = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => $isFa ? '📱 باز کردن مینی‌اپ' : '📱 Open Mini App',
                                    'web_app' => ['url' => $appUrl],
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        $telegram->sendMessage($this->chatId, $this->message, $options);
    }
}
