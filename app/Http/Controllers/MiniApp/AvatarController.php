<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serves the user's locally-cached Telegram profile photo.
 *
 * Telegram's bot-file URLs are only valid for ~1 hour after getFile(),
 * so we download the photo bytes into `storage/app/avatars/{id}.{ext}`
 * (see \App\Jobs\RefreshUserProfilePhoto) and serve them through this
 * controller. The URL stored on `users.photo_url` points here, so the
 * Mini App can render the avatar on every page load without a fresh
 * Bot API call.
 *
 * The route is intentionally PUBLIC (no auth) so that <img src="...">
 * tags in the Mini App can fetch it — Telegram's WebView cookie is
 * not always forwarded to sub-requests from <img>. We only serve the
 * photo when the user's `photo_url` actually points at this route,
 * which means the photo was placed there by our own job, not by an
 * attacker.
 */
class AvatarController extends Controller
{
    public function show(int $userId, string $ext): Response
    {
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
        if ($ext === '') {
            abort(404);
        }
        $key = "avatars/{$userId}.{$ext}";

        if (! Storage::disk('local')->exists($key)) {
            abort(404);
        }

        $bytes = Storage::disk('local')->get($key);
        $mime = $this->mimeFor($ext);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=3600, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    private function mimeFor(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'          => 'image/gif',
            default       => 'application/octet-stream',
        };
    }
}
