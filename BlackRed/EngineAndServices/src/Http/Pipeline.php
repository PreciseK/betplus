<?php

declare(strict_types=1);

namespace BlackRed\Http;

/**
 * Runs a list of middleware around a final handler.
 *
 * Built once per request from the route's middleware list + the controller
 * action. Iterating in reverse so the first middleware in the list runs first
 * (outermost).
 */
final class Pipeline
{
    /**
     * @param list<Middleware> $middleware
     * @param callable(Request): Response $finalHandler
     */
    public function __construct(
        private readonly array $middleware,
        private readonly mixed $finalHandler,
    ) {
    }

    public function handle(Request $request): Response
    {
        $next = $this->finalHandler;

        // Walk in reverse so the first middleware in the array is the outermost
        for ($i = count($this->middleware) - 1; $i >= 0; $i--) {
            $current = $this->middleware[$i];
            $previousNext = $next;
            $next = static function (Request $req) use ($current, $previousNext): Response {
                return $current->process($req, $previousNext);
            };
        }

        return $next($request);
    }
}
