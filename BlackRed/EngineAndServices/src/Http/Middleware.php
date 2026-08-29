<?php

declare(strict_types=1);

namespace BlackRed\Http;

/**
 * Middleware contract.
 *
 * Each middleware receives the request and a "next" callable that invokes the
 * remaining pipeline. It returns a Response — either by short-circuiting
 * (e.g. auth failure) or by calling $next() and possibly modifying the result.
 *
 * Pattern: classic onion / Russian doll. Outermost middleware sees the request
 * first and the response last.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
