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

        // The /avatars/{id} endpoint 302-redirects the browser to Telegram's
        // CDN URL, which embeds the bot token in the path. To minimize token
        // leakage we send `Referrer-Policy: no-referrer` on the redirect
        // response itself so the browser does NOT include our origin (let
        // alone the avatar URL) in the Referer header when it follows the
        // redirect to api.telegram.org. (The avatar controller also sets
        // this header explicitly, but the middleware-level guarantee is
        // the source of truth — it survives controller refactors.)
        if ($request->is('avatars/*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
