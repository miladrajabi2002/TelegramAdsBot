<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastBatch;
use App\Models\Admin;
use App\Models\Broadcast;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastMediaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_queue_a_photo_broadcast_in_private_storage(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = Admin::create([
            'name' => 'Broadcast Admin',
            'email' => 'broadcast@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        User::factory()->create(['account_status' => 'active']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.broadcasts.store'), [
                'title' => 'Media announcement',
                'message' => 'A caption for the photo.',
                'media' => UploadedFile::fake()->createWithContent(
                    'announcement.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                ),
                'audience' => 'all',
                'confirmed' => '1',
            ])
            ->assertRedirect();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('photo', $broadcast->media_type);
        $this->assertSame('local', $broadcast->media_disk);
        Storage::disk('local')->assertExists($broadcast->media_path);
        $this->assertSame(1, $broadcast->recipients()->count());
        Queue::assertPushed(SendBroadcastBatch::class, fn ($job) => $job->broadcastId === $broadcast->getKey());
    }

    #[Test]
    public function gif_is_recorded_as_a_telegram_animation(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = Admin::create([
            'name' => 'Broadcast Admin',
            'email' => 'gif@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.broadcasts.store'), [
                'title' => 'GIF announcement',
                'message' => 'Animated update.',
                'media' => UploadedFile::fake()->create('update.gif', 200, 'image/gif'),
                'audience' => 'all',
                'confirmed' => '1',
            ]);

        $this->assertSame('animation', Broadcast::query()->firstOrFail()->media_type);
    }

    #[Test]
    public function first_media_delivery_caches_telegram_file_id_for_following_recipients(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('broadcast-media/cached.gif', 'GIF89a');
        $admin = Admin::create([
            'name' => 'Broadcast Admin',
            'email' => 'cache@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['account_status' => 'active']);
        $broadcast = Broadcast::create([
            'admin_id' => $admin->getKey(),
            'title' => 'Cached animation',
            'message' => 'Animation caption',
            'media_type' => 'animation',
            'media_disk' => 'local',
            'media_path' => 'broadcast-media/cached.gif',
            'audience_filters' => ['audience' => 'all'],
            'status' => 'queued',
            'scheduled_at' => now(),
        ]);
        $broadcast->recipients()->create([
            'user_id' => $user->getKey(),
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('sendMedia')
            ->once()
            ->with($user->telegram_user_id, 'animation', Mockery::on(fn ($path) => str_ends_with($path, 'cached.gif')), 'Animation caption')
            ->andReturn(['animation' => ['file_id' => 'cached-gif-file-id']]);

        (new SendBroadcastBatch($broadcast->getKey()))->handle($telegram);

        $this->assertSame('cached-gif-file-id', $broadcast->refresh()->telegram_file_id);
        $this->assertSame('sent', $broadcast->recipients()->firstOrFail()->status);
        $this->assertSame('completed', $broadcast->status);
    }
}
