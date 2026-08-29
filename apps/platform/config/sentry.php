<?php

declare(strict_types=1);

/**
 * Inert until SENTRY_LARAVEL_DSN is set — no events are captured or sent with it empty
 * (sentry-laravel checks this before initializing the SDK). See .env.example for the
 * one env var needed to activate this, once a real Sentry project exists.
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Only send code from this app in stack traces, not vendor/ noise.
    'in_app_include' => [
        base_path('app'),
    ],

    'environment' => env('APP_ENV'),
    'release' => env('SENTRY_RELEASE'),

    // Performance tracing — 0 by default (errors are still captured regardless of this;
    // this only controls the separate transaction/APM sampling). Raise once Sentry usage
    // is actually budgeted for — even 0.1-0.2 gives a useful performance signal without
    // a large event-volume bill.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'send_default_pii' => false,

    // Never leak player wallet/identity data or operator credentials into an error report.
    'breadcrumbs' => [
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
    ],
];
