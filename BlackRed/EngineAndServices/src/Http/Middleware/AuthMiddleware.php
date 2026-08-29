<?php

declare(strict_types=1);

namespace BlackRed\Http\Middleware;

use BlackRed\Auth\SessionManager;
use BlackRed\Bootstrap\Config;
use BlackRed\Http\Middleware;
use BlackRed\Http\Request;
use BlackRed\Http\Response;

/**
 * Authentication middleware.
 *
 * Reads the session cookie, looks up the session in the database, and
 * attaches the player ID + session ID to the request as attributes.
 *
 * Two operating modes:
 *   - REQUIRED: returns 401 if there's no valid session
 *   - OPTIONAL: lets the request through but with no player_id set
 *
 * Routes that need authentication add this middleware as REQUIRED. The
 * default for the API is REQUIRED — only public endpoints (signup, login,
 * health check) use OPTIONAL or skip auth altogether.
 */
final class AuthMiddleware implements Middleware
{
    public const MODE_REQUIRED = 'required';
    public const MODE_OPTIONAL = 'optional';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly string $mode = self::MODE_REQUIRED,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $cookieName = $this->config->string('SESSION_COOKIE_NAME', 'BR_SESSION');
        $token = $this->extractCookie($request, $cookieName);

        if ($token !== null) {
            $session = $this->sessions->findByToken($token);
            if ($session !== null) {
                $request->setAttribute('player_id', (int)$session['playerId']);
                $request->setAttribute('session_id', (int)$session['id']);
                $request->setAttribute('msisdn', (string)$session['msisdn']);
                return $next($request);
            }
        }

        if ($this->mode === self::MODE_REQUIRED) {
            throw HttpException::unauthorized();
        }

        return $next($request);
    }

    /**
     * Parse the Cookie header and find a specific cookie value.
     *
     * We don't use $_COOKIE because we want a single source of truth from
     * Request, and because PHP's parsing is lenient in ways that have caused
     * security issues historically (cookie smuggling). Our parser is strict.
     */
    private function extractCookie(Request $request, string $name): ?string
    {
        $header = $request->header('cookie');
        if ($header === null) {
            return null;
        }

        // Cookie header format: "name1=value1; name2=value2; ..."
        // We do a strict split on "; " (with the space) and then on the FIRST "="
        // only — values can legitimately contain "=" but names cannot.
        foreach (explode(';', $header) as $pair) {
            $pair = ltrim($pair);
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $cookieName = substr($pair, 0, $eq);
            if ($cookieName !== $name) {
                continue;
            }
            $value = substr($pair, $eq + 1);
            // Defence: cookie values must be hex tokens of plausible length.
            if (preg_match('/^[a-f0-9]{32,128}$/', $value) === 1) {
                return $value;
            }
            return null;
        }
        return null;
    }
}
