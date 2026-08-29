<?php

declare(strict_types=1);

// apps/web is a static export (separate origin from this API, in dev and in production —
// see next.config.ts output:"export"). Cookie-based sessions (Story 1.11) need an explicit
// origin allowlist and supports_credentials:true; '*' cannot be combined with credentials.
return [
    'paths' => ['v1/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
