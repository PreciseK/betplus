<?php

declare(strict_types=1);

namespace BlackRed\Http;

/**
 * Immutable HTTP response representation.
 *
 * Controllers and middleware return Response objects. The bootstrap is the
 * only place that actually sends headers and writes to the output buffer.
 * This separation makes the response inspectable and testable.
 */
final class Response
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * Build a JSON response. Default status 200.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            status: $status,
            body: $data,
            headers: array_merge(
                ['Content-Type' => 'application/json; charset=utf-8'],
                $headers,
            ),
        );
    }

    /**
     * A standard error response shape. Always includes `error` (machine-readable)
     * and `message` (human-readable). NEVER include exception traces, file paths,
     * or any internal detail.
     */
    public static function error(string $error, string $message, int $status = 400, array $extra = []): self
    {
        $body = ['error' => $error, 'message' => $message];
        if (!empty($extra)) {
            $body = array_merge($body, $extra);
        }
        return self::json($body, $status);
    }

    public static function noContent(): self
    {
        return new self(status: 204, body: null);
    }

    /**
     * Add or override a header. Returns a NEW Response (immutable).
     */
    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->status, $this->body, $headers);
    }

    /**
     * Send the response to the client. Called once, by the bootstrap.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->body === null) {
            return;
        }

        if (is_string($this->body)) {
            echo $this->body;
            return;
        }

        // Serialize as JSON. JSON_THROW_ON_ERROR ensures malformed data is loud.
        echo json_encode(
            $this->body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
