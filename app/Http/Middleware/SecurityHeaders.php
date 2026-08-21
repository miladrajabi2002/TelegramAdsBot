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

        // Use route names rather than a hard-coded /admin path. The admin URL
        // is intentionally hidden behind a configurable prefix.
        if ($request->routeIs('admin.*')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if ($request->routeIs('admin.kyc.*') || $request->routeIs('app.identity.*')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        }

        if ($request->routeIs('avatar.show')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
