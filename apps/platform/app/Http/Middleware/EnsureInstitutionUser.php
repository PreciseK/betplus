<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\BackOffice\InstitutionAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-BO-014 — the back office's own auth guard, structurally separate from
 * EnsureAccessToken (players): a different bearer token namespace entirely
 * (InstitutionAuthService's 'institution-token:' cache prefix), so a leaked player
 * token can never be replayed here and vice versa.
 */
class EnsureInstitutionUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $user = $token !== null ? app(InstitutionAuthService::class)->resolveToken($token) : null;

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        $request->attributes->set('institutionUser', $user);

        return $next($request);
    }
}
