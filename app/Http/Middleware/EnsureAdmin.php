<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();

        if (! $admin || ! $admin->is_active) {
            auth('admin')->logout();

            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('admin.login');
        }

        // The admin panel is always rendered in Persian: every label,
        // status chip, audit row and review note is written in fa_IR, and
        // every date is formatted as a Jalali (Shamsi) date in Asia/Tehran.
        // Force the locale here so app()->isLocale('fa') returns true in
        // every admin view without per-controller boilerplate.
        app()->setLocale('fa');

        return $next($request);
    }
}
