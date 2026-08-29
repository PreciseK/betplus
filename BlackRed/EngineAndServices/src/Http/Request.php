<?php

declare(strict_types=1);

namespace BlackRed\Http;

/**
 * Immutable HTTP request representation.
 *
 * Built once from PHP superglobals at the top of the request, then passed
 * through middleware and controllers. Controllers should NEVER read $_GET,
 * $_POST, $_SERVER, etc. directly — always go through this object so the
 * codebase has a single, testable point of truth.
 *
 * The body is parsed lazily and cached.
 */
final class Request
{
    private ?array $jsonBody = null;
    private bool $jsonParsed = false;

    /** @var array<string, mixed> */
    private array $attributes = [];

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $rawBody,
        /** @var array<string, string> */
        public readonly array $headers,
        /** @var array<string, mixed> */
        public readonly array $query,
        public readonly string $clientIp,
        public readonly string $userAgent,
        public readonly string $requestId,
    ) {
    }

    /**
     * Construct from PHP superglobals. Called once per request from the bootstrap.
     *
     * If $basePath is provided, it is stripped from the start of REQUEST_URI.
     * This handles the case where the app is mounted under a subdirectory
     * (e.g. https://host.com/blackred/public/api/health where basePath is
     * "/blackred/public" and the routed path becomes "/api/health").
     */
    public static function fromGlobals(string $basePath = ''): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Strip the configured base path prefix if present.
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
        }

        // Always read raw body for JSON requests
        $rawBody = file_get_contents('php://input') ?: '';

        $headers = self::extractHeaders();
        $clientIp = self::resolveClientIp();
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500)
            : '';

        // Use existing request ID if upstream provided one, else generate.
        // Lowercased and length-bounded for safety.
        $requestId = $headers['x-request-id'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(8));
        }

        return new self(
            method: $method,
            path: $path,
            rawBody: $rawBody,
            headers: $headers,
            query: $_GET ?? [],
            clientIp: $clientIp,
            userAgent: $userAgent,
            requestId: $requestId,
        );
    }

    /**
     * Extract headers from $_SERVER, lower-casing keys for consistent lookup.
     */
    private static function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string)$value;
            }
        }
        // Special non-HTTP_ headers
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /**
     * Resolve the real client IP. We DO NOT trust X-Forwarded-For unless we know
     * we're behind a trusted proxy. On shared hosting we use REMOTE_ADDR directly.
     * In production behind a proxy, this method needs to be tightened to validate
     * the proxy chain.
     */
    private static function resolveClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        $ct = $this->header('content-type') ?? '';
        return str_contains(strtolower($ct), 'application/json');
    }

    /**
     * Decode the JSON body (cached). Returns an empty array if no body.
     * Throws on invalid JSON so the caller can return 400.
     */
    public function json(): array
    {
        if ($this->jsonParsed) {
            return $this->jsonBody ?? [];
        }
        $this->jsonParsed = true;

        if ($this->rawBody === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('Request body must be a JSON object');
        }
        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $val = $this->query[$key] ?? $default;
        return $val === null ? null : (string)$val;
    }

    /**
     * Attributes are mutable runtime data attached during middleware processing
     * (e.g. authenticated player ID). Kept separate from the immutable request data.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
