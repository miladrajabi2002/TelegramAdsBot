<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_telegram_update_is_processed_only_once(): void
    {
        Queue::fake();
        config(['services.telegram.webhook_secret' => 'test-webhook-secret']);

        $payload = [
            'update_id' => 987654321,
            'message' => [
                'message_id' => 42,
                'from' => [
                    'id' => 123456789,
                    'first_name' => 'کاربر',
                    'language_code' => 'fa',
                ],
                'chat' => ['id' => 123456789, 'type' => 'private'],
                'text' => '/help',
            ],
        ];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'];

        $this->postJson(route('webhooks.telegram'), $payload, $headers)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->postJson(route('webhooks.telegram'), $payload, $headers)
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => true]);

        Queue::assertPushed(SendTelegramMessage::class, 1);
        $this->assertDatabaseHas('telegram_webhook_events', [
            'update_id' => 987654321,
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('users', [
            'telegram_user_id' => 123456789,
            'locale' => 'fa',
        ]);
    }

    public function test_webhook_rejects_an_invalid_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'expected-secret']);

        $this->postJson(route('webhooks.telegram'), ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->assertForbidden();
    }
}
