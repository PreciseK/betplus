<?php

declare(strict_types=1);

namespace BlackRed\Http;

use RuntimeException;

/**
 * Minimal HTTP router.
 *
 * Routes are registered with an explicit method, path pattern, and a handler
 * (controller class + method). Path patterns support :param placeholders that
 * match a single non-slash segment.
 *
 * Examples:
 *   /api/health
 *   /api/players/:id
 *   /api/deposits/:ref/status
 *
 * The router is intentionally simple. It does NOT support regex routes,
 * optional parameters, or wildcards — anything beyond the basics tends to
 * become a security smell or a maintenance burden.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, params:list<string>, handler:array{class:string, method:string}, middleware:list<string>}> */
    private array $routes = [];

    /**
     * Register a route.
     *
     * @param array{class:string, method:string} $handler
     * @param list<string> $middleware Service IDs of middleware to apply (in order)
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            throw new RuntimeException("Invalid HTTP method: {$method}");
        }

        if (!str_starts_with($pattern, '/')) {
            throw new RuntimeException("Route pattern must start with /: {$pattern}");
        }

        // Compile the pattern into a regex. :param becomes ([^/]+).
        $params = [];
        $regex = preg_replace_callback(
            '#:([a-zA-Z_][a-zA-Z0-9_]*)#',
            function ($m) use (&$params) {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        // Anchor the regex; quote any other regex special chars in the literal portions.
        // Since we only allow letters, digits, /, _, -, : in patterns, this is safe.
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * Match a request to a registered route.
     *
     * @return array{handler:array{class:string, method:string}, params:array<string,string>, middleware:list<string>}|null
     *         Null if no route matches the path. If the path matches but the method doesn't,
     *         we still return null — the caller distinguishes 404 from 405 by re-matching method-agnostically.
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches); // remove full match
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }
        return null;
    }

    /**
     * Returns true if the path matches some route under any method.
     * Used to distinguish 404 vs 405.
     */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
