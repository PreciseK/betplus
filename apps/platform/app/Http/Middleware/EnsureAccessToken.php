<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Player;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the access token issued by SessionService (Story 1.11) from the Authorization
 * bearer header or the access_token cookie, and attaches the player to the request.
 * Cache-backed (see SessionService) rather than a DB lookup — the whole point of a
 * short-lived access token is a cheap check on every request.
 */
class EnsureAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->cookie('access_token');
        $playerId = $token !== null ? Cache::get("access-token:$token") : null;

        if ($playerId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $player = Player::find($playerId);
        if ($player === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($player->accountStatus === 'inactive' || $player->accountStatus === 'closed') {
            return response()->json([
                'message' => 'Your account is deactivated. You may request reactivation through Support within 6 months of deactivation.',
                'account_status' => $player->accountStatus,
                'deactivated_at' => $player->deletedAt?->toIso8601String(),
            ], 403);
        }

        $request->attributes->set('player', $player);

        return $next($request);
    }
}
