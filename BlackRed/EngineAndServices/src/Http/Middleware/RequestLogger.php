<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use BlackRed\Http\Middleware;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;

/**
 * Logs one structured entry per request: method, path, status, latency, IP, user agent.
 *
 * Sits near the outside of the pipeline (after ErrorHandler) so it sees the
 * final response status including errors converted by ErrorHandler.
 */
final class RequestLogger implements Middleware
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        $this->logger->info('http_request', [
            'request_id' => $request->requestId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->status,
            'latency_ms' => $latencyMs,
            'ip' => $request->clientIp,
            'ua' => $request->userAgent,
            'player_id' => $request->getAttribute('player_id'),
        ]);

        // Echo the request ID back to the client for cross-system correlation.
        return $response->withHeader('X-Request-Id', $request->requestId);
    }
}
