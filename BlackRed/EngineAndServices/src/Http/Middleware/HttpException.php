<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use RuntimeException;

/**
 * Throw this from anywhere in the request lifecycle to short-circuit with a
 * specific HTTP status, error code, and human-readable message.
 *
 * The ErrorHandler middleware catches these and converts to Response::error().
 *
 * Use sparingly — prefer returning Response::error() directly from controllers
 * where the flow is linear. HttpException is for cases where the failure
 * happens deep in a service and bubbling a Response back would be awkward.
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message, array $extra = []): self
    {
        return new self(400, $code, $message, $extra);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function methodNotAllowed(string $message = 'Method not allowed for this resource'): self
    {
        return new self(405, 'method_not_allowed', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): self
    {
        return new self(429, 'rate_limit_exceeded', $message);
    }
}
