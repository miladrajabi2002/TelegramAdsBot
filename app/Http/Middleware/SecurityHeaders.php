<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if ($request->is('admin/*')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if ($request->is('admin/kyc/*') || $request->is('app/identity*')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        }

        // Avatar bytes are proxied by our own server and the Telegram bot token
        // never reaches the browser. Keep referrer suppression anyway so local
        // avatar/user identifiers are not needlessly sent to external pages.
        if ($request->is('avatars/*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
