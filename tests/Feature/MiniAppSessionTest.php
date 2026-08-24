<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiniAppSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function entry_loads_the_telegram_sdk_before_the_application_bundle(): void
    {
        $html = $this->get('/app')
            ->assertOk()
            ->getContent();

        $telegramSdkPosition = strpos($html, 'https://telegram.org/js/telegram-web-app.js');
        $applicationBundlePosition = strpos($html, '<script type="module"');

        $this->assertNotFalse($telegramSdkPosition);
        $this->assertNotFalse($applicationBundlePosition);
        $this->assertLessThan($applicationBundlePosition, $telegramSdkPosition);
        $this->assertStringNotContainsString('miniapp-entry', $html);
    }

    #[Test]
    public function session_accepts_fresh_signed_telegram_init_data(): void
    {
        config([
            'services.telegram.bot_token' => '123456:test-token',
            'services.telegram.init_data_ttl' => 900,
        ]);

        $response = $this->postJson('/app/session', [
            'init_data' => $this->signedInitData([
                'auth_date' => now()->timestamp,
                'query_id' => 'query-1',
                'user' => json_encode([
                    'id' => 424242,
                    'first_name' => 'Mini',
                    'last_name' => 'App',
                    'language_code' => 'en',
                ]),
            ]),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('auth_method', 'init_data')
            ->assertJsonPath('redirect', route('app.home'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas(User::class, [
            'telegram_user_id' => 424242,
            'display_name' => 'Mini App',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function signedInitData(array $data): string
    {
        ksort($data, SORT_STRING);
        $checkString = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secret = hash_hmac('sha256', '123456:test-token', 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secret);

        return http_build_query($data);
    }
}
