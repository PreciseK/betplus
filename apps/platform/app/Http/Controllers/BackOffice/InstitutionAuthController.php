<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\BackOffice\InstitutionAuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\InstitutionMfaRequest;
use App\Http\Requests\BackOffice\InstitutionSignInRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstitutionAuthController extends Controller
{
    public function __construct(private readonly InstitutionAuthService $auth)
    {
    }

    /** POST /backoffice/v1/auth/sign-in */
    public function signIn(InstitutionSignInRequest $request): JsonResponse
    {
        $result = $this->auth->signIn(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        if ($result['status'] === 'invalid_credentials') {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        if ($result['status'] === 'account_suspended') {
            return response()->json(['message' => 'Account suspended.'], 403);
        }
        if ($result['status'] === 'ip_not_allowlisted') {
            return response()->json(['message' => 'This role requires signing in from an allowlisted IP.'], 403);
        }
        if ($result['status'] === 'too_many_attempts') {
            return response()->json(['message' => 'Too many sign-in attempts. Try again later.'], 429);
        }

        return response()->json($result);
    }

    /** POST /backoffice/v1/auth/mfa */
    public function verifyMfa(InstitutionMfaRequest $request): JsonResponse
    {
        $result = $this->auth->verifyMfa($request->string('challenge_id')->toString(), $request->string('code')->toString());

        if ($result['status'] === 'too_many_attempts') {
            return response()->json(['message' => 'Too many attempts. Sign in again to get a new code.'], 429);
        }
        if ($result['status'] !== 'ok') {
            return response()->json(['message' => match ($result['status']) {
                'challenge_expired' => 'Sign-in expired, start again.',
                default => 'Invalid code.',
            }], 401);
        }

        return response()->json($result);
    }
}
