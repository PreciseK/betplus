<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\TurnstileVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Cloudflare Turnstile token on public entry points (registration, sign-in)
 * to prevent automated credential stuffing, brute-forcing, and SMS toll fraud.
 */
class VerifyTurnstile
{
    public function __construct(private readonly TurnstileVerifier $verifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Extract token from standard Cloudflare Turnstile field names or custom headers
        $token = $request->input('cf-turnstile-response')
            ?? $request->input('cf_turnstile_response')
            ?? $request->input('turnstile_token')
            ?? $request->header('X-Turnstile-Token')
            ?? $request->header('CF-Turnstile-Token');

        $isValid = $this->verifier->verify(
            token: is_string($token) ? $token : null,
            ipAddress: $request->ip(),
        );

        if (!$isValid) {
            return response()->json([
                'message' => 'Security verification failed. Please complete the challenge and try again.',
                'error' => 'turnstile_verification_failed',
            ], 422);
        }

        return $next($request);
    }
}
