<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramBotClientMediaTest extends TestCase
{
    #[Test]
    public function it_uploads_local_animation_as_multipart_media(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['animation' => ['file_id' => 'animation-file-id']],
            ]),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'telegram-media-');
        file_put_contents($path, 'GIF89a');

        try {
            $result = (new TelegramBotClient)->sendMedia(12345, 'animation', $path, 'Test caption');
        } finally {
            @unlink($path);
        }

        $this->assertSame('animation-file-id', data_get($result, 'animation.file_id'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendAnimation')
            && $request->isMultipart());
    }

    #[Test]
    public function it_reuses_a_telegram_file_id_without_multipart_upload(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['video' => ['file_id' => 'reused-video-id']],
            ]),
        ]);

        (new TelegramBotClient)->sendMedia(12345, 'video', 'reused-video-id', 'Test caption');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendVideo')
            && ! $request->isMultipart()
            && $request['video'] === 'reused-video-id');
    }
}
