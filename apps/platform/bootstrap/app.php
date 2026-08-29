<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/v1.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Machine-to-machine (provider callbacks, USSD gateway) — no /v1 prefix,
            // no player session, own signature verification per endpoint
            // (Betplus_PRD.md §12.4).
            Route::middleware('api')->group(__DIR__.'/../routes/internal.php');

            // REQ-BO-014 — "served on a network surface distinct from the player API,
            // with its own authentication guard." /backoffice/v1, never /v1.
            Route::middleware('api')->group(__DIR__.'/../routes/backoffice.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'auth.token' => \App\Http\Middleware\EnsureAccessToken::class,
            'idempotent' => \App\Http\Middleware\EnsureIdempotency::class,
            'opay.callback' => \App\Http\Middleware\VerifyOpayCallback::class,
            'opay.payout-callback' => \App\Http\Middleware\VerifyOpayPayoutCallback::class,
            'institution.auth' => \App\Http\Middleware\EnsureInstitutionUser::class,
            'institution.role' => \App\Http\Middleware\EnsureInstitutionRole::class,
            'ussd.gateway' => \App\Http\Middleware\VerifyUssdGatewaySignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->is('backoffice/*'),
        );

        // No-ops when SENTRY_LARAVEL_DSN is unset (config/sentry.php) — safe to leave
        // registered in every environment, including local dev and CI.
        \Sentry\Laravel\Integration::handles($exceptions);
    })->create();
