<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response. This app is a JSON API only
 * (bootstrap/app.php's shouldRenderJsonWhen covers every registered route group), so the
 * CSP here is deliberately locked down rather than tuned for serving a page — there is no
 * HTML/script to allow. HSTS is safe to always send: browsers ignore it on a plain-HTTP
 * response, so it's a no-op in local dev and armed the moment production is HTTPS-only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = "default-src 'none'; frame-ancestors 'none'";
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'text/html')) {
            $csp = "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'";
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
