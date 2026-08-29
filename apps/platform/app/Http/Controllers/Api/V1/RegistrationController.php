<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\IdentityConfirmationService;
use App\Domain\Identity\RegistrationService;
use App\Http\Controllers\Api\V1\Concerns\IssuesSessionCookies;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class RegistrationController extends Controller
{
    use IssuesSessionCookies;

    public function __construct(
        private readonly RegistrationService $registration,
        private readonly IdentityConfirmationService $identity,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->handle(fn () => $this->registration->startOrResume($request->string('msisdn')->toString()));
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        return $this->handle(fn () => $this->registration->verify(
            $request->string('msisdn')->toString(),
            $request->string('code')->toString(),
        ));
    }

    public function confirmIdentity(RegisterRequest $request): JsonResponse
    {
        return $this->handle(fn () => $this->identity->confirmIdentity($request->string('msisdn')->toString()));
    }

    public function complete(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->identity->completeRegistration($request->string('msisdn')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->withSessionCookies(response()->json($result), $result);
    }

    /** @param callable(): array<string, mixed> $action */
    private function handle(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
