<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\SessionService;
use App\Http\Controllers\Api\V1\Concerns\IssuesSessionCookies;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SessionController extends Controller
{
    use IssuesSessionCookies;

    public function __construct(private readonly SessionService $sessions)
    {
    }

    public function complete(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->sessions->signIn($request->string('msisdn')->toString(), ipAddress: $request->ip());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['status'] === 'too_many_attempts') {
            return response()->json($result, 429);
        }

        return $this->withSessionCookies(response()->json($result), $result);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        // The browser's refresh_token cookie is HttpOnly by design (sessionGateway.ts never
        // touches it) — the body value only exists for a non-browser caller that manages its
        // own token storage. Cookie takes priority since that's the actual browser flow.
        $refreshToken = $request->cookie('refresh_token') ?? $request->string('refresh_token')->toString();
        $result = $this->sessions->refresh($refreshToken);

        return $this->withSessionCookies(response()->json($result), $result);
    }

    public function signOut(Request $request): JsonResponse
    {
        $this->sessions->signOut(
            $request->bearerToken() ?? $request->cookie('access_token'),
            $request->cookie('refresh_token'),
        );

        return $this->withoutSessionCookies(response()->json(['status' => 'signed_out']));
    }
}
