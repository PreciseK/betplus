<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use BlackRed\Auth\RateLimiter;
use BlackRed\Http\Middleware;
use BlackRed\Http\Request;
use BlackRed\Http\Response;

/**
 * Rate limit middleware.
 *
 * Wraps an endpoint with a per-IP rate limit. If the limit is exceeded,
 * returns 429 Too Many Requests immediately without invoking the controller.
 *
 * Per-route configuration: each route registers this middleware with its
 * own bucket name and limit, e.g. ("auth.login", 5). Different endpoints
 * have different limits (login is tighter than general browsing).
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $bucket,
        private readonly int $maxPerMinute,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $key = $this->bucket . ':' . $request->clientIp;
        if (!$this->limiter->attempt($key, $this->maxPerMinute)) {
            throw HttpException::tooManyRequests(
                "Too many requests. Please wait a minute and try again."
            );
        }
        return $next($request);
    }
}
