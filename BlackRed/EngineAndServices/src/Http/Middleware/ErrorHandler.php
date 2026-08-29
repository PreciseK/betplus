<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use BlackRed\Http\Middleware;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;
use JsonException;
use Throwable;

/**
 * Outermost middleware. Catches any uncaught exception and converts it to a
 * sanitised JSON response.
 *
 * In production, error details are NEVER returned to the client — only logged.
 * In development, more detail can be included to aid debugging.
 */
final class ErrorHandler implements Middleware
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (JsonException $e) {
            // Malformed request body — client error, not server error.
            $this->logger->info('json_decode_failed', [
                'request_id' => $request->requestId,
                'message' => $e->getMessage(),
            ]);
            return Response::error(
                'invalid_request',
                'Request body is not valid JSON.',
                400,
            );
        } catch (HttpException $e) {
            // Application-thrown HTTP exception — caller decided the status & message.
            // These are expected; log at info level.
            $this->logger->info('http_exception', [
                'request_id' => $request->requestId,
                'status' => $e->status,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
            return Response::error($e->errorCode, $e->getMessage(), $e->status, $e->extra);
        } catch (Throwable $e) {
            // Truly unexpected. Log everything we know, return a sanitised response.
            $this->logger->error('unhandled_exception', [
                'request_id' => $request->requestId,
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $body = [
                'error' => 'internal_server_error',
                'message' => 'The server encountered an unexpected condition.',
                'request_id' => $request->requestId,
            ];

            if ($this->debug) {
                $body['debug'] = [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ];
            }

            return Response::json($body, 500);
        }
    }
}
