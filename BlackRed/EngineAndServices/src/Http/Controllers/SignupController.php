<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Auth\SessionManager;
use BlackRed\Auth\SignupService;
use BlackRed\Bootstrap\Config;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Integrations\AnmLookupException;
use BlackRed\Logging\Logger;
use BlackRed\Support\GhanaPhone;
use BlackRed\Validation\Validator;

/**
 * Three-step signup endpoints.
 *
 *   POST /api/auth/signup/lookup   — phone+network → MoMo name (modal-friendly)
 *   POST /api/auth/signup/verify   — phone+code   → check authtoken
 *   POST /api/auth/signup/complete — phone+pin → create account, auto-login
 *
 * Frontend uses these in sequence. Each step requires the previous to be done
 * (enforced server-side via signupSession state, not by trusting the frontend).
 */
final class SignupController
{
    public function __construct(
        private readonly SignupService $signup,
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /api/auth/signup/lookup
     * Body: { phone, network }
     *
     * "network" is the UI's value: "mtn" | "at" | "telecel"
     * We translate to schema codes: MTN | ATL | TEL
     */
    public function lookup(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone = $v->requirePhone('phone');
        $networkUi = $v->requireOneOf('network', ['mtn', 'at', 'telecel']);

        $provider = match ($networkUi) {
            'mtn'     => 'MTN',
            'at'      => 'ATL',
            'telecel' => 'TEL',
        };

        try {
            $result = $this->signup->lookup($phone, $provider, $request->clientIp);
        } catch (AnmLookupException $e) {
            $this->logger->info('signup_lookup_failed', [
                'phone' => $phone,
                'provider' => $provider,
                'reason' => $e->errorCode,
            ]);
            // 422 Unprocessable Entity — the request was well-formed but ANM
            // could not satisfy it. Frontend shows this in a modal.
            return Response::error($e->errorCode, $e->getMessage(), 422);
        }

        return Response::json([
            'ok'              => true,
            'phone'           => $result['phone'],
            'phoneFormatted'  => GhanaPhone::formatForDisplay($result['phone']),
            'provider'        => $result['provider'],
            'registeredName'  => $result['registeredName'],
        ]);
    }

    /**
     * POST /api/auth/signup/verify
     * Body: { phone, code }
     *
     * Code is the 4-character alphanumeric OTP from the *920*995# USSD.
     */
    public function verify(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone = $v->requirePhone('phone');
        $code = $v->requireOtpCode('code', 4);

        $this->signup->verifyOtp($phone, $code);

        return Response::json(['ok' => true]);
    }

    /**
     * POST /api/auth/signup/complete
     * Body: { phone, pin }   (pin = 4 digits)
     *
     * Auto-login on success via session cookie.
     */
    public function complete(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone = $v->requirePhone('phone');
        $pin = $v->requirePin('pin');

        $playerId = $this->signup->complete($phone, $pin);

        // Auto-login: issue a session immediately
        $session = $this->sessions->create(
            $playerId,
            $phone,
            'web',
            $request->clientIp,
            $request->userAgent,
        );
        $cookieHeader = $this->sessions->buildCookieHeader($session['token'], $session['expiresAt']);

        return Response::json([
            'ok'        => true,
            'playerId'  => $playerId,
            'phone'     => $phone,
            'expiresAt' => $session['expiresAt'],
        ])->withHeader('Set-Cookie', $cookieHeader);
    }
}
