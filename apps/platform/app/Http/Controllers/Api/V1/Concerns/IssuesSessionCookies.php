<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;

/** The one place REQ-ID-026's cookie attributes are set — every issuance point uses this. */
trait IssuesSessionCookies
{
    /** @param array<string, mixed> $result */
    private function withSessionCookies(JsonResponse $response, array $result): JsonResponse
    {
        if (!isset($result['access_token'], $result['refresh_token'])) {
            return $response;
        }

        // Secure by default; the one carve-out is 'local' — a browser refuses to store
        // a Secure cookie at all over the plain-HTTP origin local dev normally runs on
        // (http://localhost:8000), which would otherwise break sign-in locally. Every
        // other environment (production, staging, and 'testing' — so this suite can
        // actually verify the flag) stays Secure.
        $isSecure = !app()->environment('local');

        // ->cookie() forwards via func_get_args() to the global cookie() helper, which
        // means named arguments don't resolve against it — positional only.
        // (name, value, minutes, path, domain, secure, httpOnly, raw, sameSite)
        return $response
            ->cookie('access_token', $result['access_token'], 30, null, null, $isSecure, true, false, 'lax')
            ->cookie('refresh_token', $result['refresh_token'], 60 * 24 * 30, null, null, $isSecure, true, false, 'lax')
            // Deliberately NOT HttpOnly — this is the one signal the frontend is allowed to
            // read (document.cookie) to gate UI without a round-trip. It tracks "has a
            // session worth trying", not token validity; the access token's own 30-minute
            // TTL and the refresh flow are what actually gate the API.
            ->cookie('betplus_signed_in', '1', 60 * 24 * 30, null, null, $isSecure, false, false, 'lax');
    }

    /** Sign-out: expire all three cookies immediately. */
    private function withoutSessionCookies(JsonResponse $response): JsonResponse
    {
        // Secure by default; the one carve-out is 'local' — a browser refuses to store
        // a Secure cookie at all over the plain-HTTP origin local dev normally runs on
        // (http://localhost:8000), which would otherwise break sign-in locally. Every
        // other environment (production, staging, and 'testing' — so this suite can
        // actually verify the flag) stays Secure.
        $isSecure = !app()->environment('local');

        return $response
            ->cookie('access_token', '', -1, null, null, $isSecure, true, false, 'lax')
            ->cookie('refresh_token', '', -1, null, null, $isSecure, true, false, 'lax')
            ->cookie('betplus_signed_in', '', -1, null, null, $isSecure, false, false, 'lax');
    }
}
