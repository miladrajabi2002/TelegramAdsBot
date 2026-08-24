<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMiniAppUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('web')->check()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('app.entry');
        }

        $user = auth('web')->user();
        // Avoid a database UPDATE on every page view. Presence only needs
        // minute-level precision, while synchronous writes materially slow
        // every Mini App navigation on shared/cloud databases.
        if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        app()->setLocale(in_array($user->locale, ['fa', 'en'], true) ? $user->locale : 'fa');

        if ($user->account_status !== 'active'
            && ! $request->routeIs(
                'app.home', 'app.account', 'app.help', 'app.support.*',
                'app.logout', 'app.locale', 'app.language',
            )) {
            abort(403, 'این حساب برای عملیات مالی و ثبت سفارش محدود شده است؛ با پشتیبانی تماس بگیرید.');
        }

        return $next($request);
    }
}
