<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Validation\UnauthorizedException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramInitDataValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_an_authentic_fresh_payload(): void
    {
        config(['services.telegram.bot_token' => '123456:test-token', 'services.telegram.init_data_ttl' => 900]);
        $initData = $this->signedInitData(['auth_date' => now()->timestamp, 'query_id' => 'query-1', 'user' => json_encode(['id' => 42, 'first_name' => 'Ada'])]);

        $validated = app(TelegramInitDataValidator::class)->validate($initData);

        $this->assertSame(42, $validated['user']['id']);
    }

    #[Test]
    public function it_rejects_tampering_and_expired_payloads(): void
    {
        config(['services.telegram.bot_token' => '123456:test-token', 'services.telegram.init_data_ttl' => 60]);
        $valid = $this->signedInitData(['auth_date' => now()->timestamp, 'user' => json_encode(['id' => 42])]);
        parse_str($valid, $tampered);
        $tampered['user'] = json_encode(['id' => 99]);

        $this->expectException(UnauthorizedException::class);
        app(TelegramInitDataValidator::class)->validate(http_build_query($tampered));
    }

    #[Test]
    public function it_rejects_expired_payload(): void
    {
        config(['services.telegram.bot_token' => '123456:test-token', 'services.telegram.init_data_ttl' => 60]);

        $this->expectException(UnauthorizedException::class);
        app(TelegramInitDataValidator::class)->validate($this->signedInitData([
            'auth_date' => now()->subMinutes(2)->timestamp,
            'user' => json_encode(['id' => 42]),
        ]));
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
