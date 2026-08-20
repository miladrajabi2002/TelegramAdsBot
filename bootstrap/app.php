<?php

use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'miniapp' => \App\Http\Middleware\EnsureMiniAppUser::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'admin.permission' => \App\Http\Middleware\EnsureAdminPermission::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/telegram',
            'payments/zarinpay/callback',
            'webhooks/nowpayments',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ─── Render PaymentException as a 422 ValidationException ────────
        // PaymentException extends DomainException, so without this render
        // hook Laravel surfaces it as a generic 500. The end-user then sees
        // a meaningless "Server Error" page instead of the actionable Persian
        // message inside the exception (e.g. "Combined advertising credit
        // and wallet balance are insufficient.").
        //
        // By mapping it to ValidationException, Laravel's standard handler
        // kicks in: web requests get redirected back()->withInput() with
        // the message flashed into the $errors bag, and the user sees a
        // clear, actionable notice via <x-flash />. API requests get a 422
        // JSON body with `errors.payment`.
        //
        // We attach the error under the `payment` key so the KYC-style
        // @error('payment') block in the payment form picks it up. The
        // message itself is already user-facing (thrown in fa in
        // PaymentService::fundOrderFromWallet and friends).
        $exceptions->render(function (PaymentException $e, Request $request) {
            throw ValidationException::withMessages([
                'payment' => $e->getMessage(),
            ]);
        });
    })->create();

