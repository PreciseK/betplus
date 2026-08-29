<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\InstitutionUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-BO-001 least-privilege, REQ-BO-013 "destinations a role cannot reach are
 * removed" — enforced here server-side (the frontend hiding a nav item is not access
 * control). Usage: ->middleware('institution.role:system_admin,compliance').
 */
class EnsureInstitutionRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        /** @var InstitutionUser|null $user */
        $user = $request->attributes->get('institutionUser');
        if ($user === null || !in_array($user->role, $allowedRoles, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
