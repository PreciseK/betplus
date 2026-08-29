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

        // ->cookie() forwards via func_get_args() to the global cookie() helper, which
        // means named arguments don't resolve against it — positional only.
        // (name, value, minutes, path, domain, secure, httpOnly, raw, sameSite)
        return $response
            ->cookie('access_token', $result['access_token'], 30, null, null, true, true, false, 'lax')
            ->cookie('refresh_token', $result['refresh_token'], 60 * 24 * 30, null, null, true, true, false, 'lax')
            // Deliberately NOT HttpOnly — this is the one signal the frontend is allowed to
            // read (document.cookie) to gate UI without a round-trip. It tracks "has a
            // session worth trying", not token validity; the access token's own 30-minute
            // TTL and the refresh flow are what actually gate the API.
            ->cookie('betplus_signed_in', '1', 60 * 24 * 30, null, null, true, false, false, 'lax');
    }

    /** Sign-out: expire all three cookies immediately. */
    private function withoutSessionCookies(JsonResponse $response): JsonResponse
    {
        return $response
            ->cookie('access_token', '', -1, null, null, true, true, false, 'lax')
            ->cookie('refresh_token', '', -1, null, null, true, true, false, 'lax')
            ->cookie('betplus_signed_in', '', -1, null, null, true, false, false, 'lax');
    }
}
