<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use BlackRed\Http\Middleware;
use BlackRed\Http\Request;
use BlackRed\Http\Response;

/**
 * Applies security-relevant headers to every response.
 *
 * These are also set in public/.htaccess at the Apache level, but we set them
 * here too as defense in depth — and because some headers (like CSP) are
 * easier to manage from PHP where we know the response context.
 */
final class SecurityHeaders implements Middleware
{
    public function __construct(private readonly bool $production)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
            // Cache control for API responses: never cache.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ];

        if ($this->production) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
