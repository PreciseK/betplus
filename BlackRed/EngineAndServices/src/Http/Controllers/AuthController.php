<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Auth\ForgotPasswordService;
use BlackRed\Auth\PasswordChangeService;
use BlackRed\Auth\PasswordHasher;
use BlackRed\Auth\RateLimiter;
use BlackRed\Auth\SessionManager;
use BlackRed\Bootstrap\Config;
use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;
use BlackRed\Validation\Validator;

/**
 * Authentication endpoints (login + logout only).
 *
 * Signup endpoints live in SignupController because they're a multi-step
 * wizard with their own service.
 *
 *   POST /api/auth/login   — phone + pin → session
 *   POST /api/auth/logout  — destroys session (auth required)
 */
final class AuthController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly PasswordHasher $passwords,
        private readonly PasswordChangeService $passwordChange,
        private readonly ForgotPasswordService $forgotPassword,
        private readonly SessionManager $sessions,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /api/auth/login
     * Body: { phone, pin }   (pin = 4 digits)
     *
     * Constant-time on the failure path: PIN verification always runs even
     * when the phone is unknown, using a placeholder hash. Prevents timing
     * attacks that could enumerate registered phones.
     *
     * Lockout: 3 wrong PINs in a row trips player.lockedUntil for 15 minutes.
     * A successful login resets the counter.
     */
    public function login(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone = $v->requirePhone('phone');
        // Looser than signup-complete — let any garbage through so wrong-PIN
        // and unknown-phone take the same time (constant-time path).
        $pin = $v->requireString('pin', 1, 100);

        $player = $this->db->fetchOne(
            'SELECT id, msisdn, passwordHash, accountStatus, deletedAt,
                    failedLoginCount, lockedUntil
             FROM player WHERE msisdn = :msisdn LIMIT 1',
            ['msisdn' => $phone]
        );

        // Locked? Check before verifying — but still run the hash verify below
        // so timing stays constant whether locked or not.
        $isLocked = $player !== null
            && $player['lockedUntil'] !== null
            && strtotime((string)$player['lockedUntil']) > time();

        // Placeholder hash for the timing-safe path when the player doesn't exist.
        // Argon2id of "x", verifies in roughly the same time as a real PIN.
        $hashToCheck = $player !== null && $player['passwordHash'] !== null
            ? (string)$player['passwordHash']
            : '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXlzYWx0$cBfZ7wPV1tdYgYkb99Y9JBJpYxFxNrxf3JkLkGNEXJI';

        $pinOk = $this->passwords->verify($pin, $hashToCheck);

        // Reject locked accounts (after the constant-time verify ran).
        if ($isLocked) {
            $unlockAt = (string)$player['lockedUntil'];
            $minsLeft = max(1, (int)ceil((strtotime($unlockAt) - time()) / 60));
            $this->logger->info('login_blocked_locked', [
                'request_id' => $request->requestId,
                'msisdn'     => $phone,
                'lockedUntil'=> $unlockAt,
            ]);
            throw HttpException::unauthorized(
                "Too many wrong attempts. Try again in {$minsLeft} minute" . ($minsLeft === 1 ? '' : 's') . '.'
            );
        }

        if ($player === null
            || $player['deletedAt'] !== null
            || $player['accountStatus'] !== 'active'
            || !$pinOk
        ) {
            $reason = $player === null ? 'no_such_user' :
                      ($player['deletedAt'] !== null ? 'deleted' :
                      ($player['accountStatus'] !== 'active' ? 'not_active' :
                      'bad_pin'));

            // If the player exists and failed on PIN: increment counter, possibly lock.
            if ($player !== null && $reason === 'bad_pin') {
                $fails = (int)$player['failedLoginCount'] + 1;
                if ($fails >= 3) {
                    $this->db->execute(
                        'UPDATE player
                            SET failedLoginCount = :f,
                                lockedUntil = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                          WHERE id = :id',
                        ['f' => $fails, 'id' => $player['id']]
                    );
                    $this->logger->info('login_account_locked', [
                        'request_id' => $request->requestId,
                        'msisdn'     => $phone,
                        'failCount'  => $fails,
                    ]);
                } else {
                    $this->db->execute(
                        'UPDATE player SET failedLoginCount = :f WHERE id = :id',
                        ['f' => $fails, 'id' => $player['id']]
                    );
                }
            }

            $this->logger->info('login_failed', [
                'request_id' => $request->requestId,
                'msisdn'     => $phone,
                'reason'     => $reason,
            ]);
            throw HttpException::unauthorized('Invalid phone number or PIN');
        }

        // Re-hash if cost parameters have been bumped since this hash was created.
        if ($this->passwords->needsRehash((string)$player['passwordHash'])) {
            $newHash = $this->passwords->hash($pin);
            $this->db->execute(
                'UPDATE player SET passwordHash = :h WHERE id = :id',
                ['h' => $newHash, 'id' => $player['id']]
            );
        }

        // Reset the lockout counter on successful auth — a legitimate user
        // who fat-fingered a couple of times shouldn't carry that forward.
        if ((int)$player['failedLoginCount'] > 0 || $player['lockedUntil'] !== null) {
            $this->db->execute(
                'UPDATE player SET failedLoginCount = 0, lockedUntil = NULL WHERE id = :id',
                ['id' => $player['id']]
            );
        }

        // Reset per-IP rate-limit bucket too.
        $this->limiter->reset('auth.login:' . $request->clientIp);

        $session = $this->sessions->create(
            (int)$player['id'],
            $phone,
            'web',
            $request->clientIp,
            $request->userAgent,
        );

        $this->logger->info('login_success', [
            'request_id' => $request->requestId,
            'player_id'  => (int)$player['id'],
            'msisdn'     => $phone,
        ]);

        $cookieHeader = $this->sessions->buildCookieHeader($session['token'], $session['expiresAt']);

        return Response::json([
            'ok'        => true,
            'playerId'  => (int)$player['id'],
            'phone'     => $phone,
            'expiresAt' => $session['expiresAt'],
        ])->withHeader('Set-Cookie', $cookieHeader);
    }

    /**
     * POST /api/auth/logout
     * Authenticated. Idempotent.
     */
    public function logout(Request $request, array $params): Response
    {
        $sessionId = $request->getAttribute('session_id');
        if (is_int($sessionId)) {
            $this->sessions->terminate($sessionId);
        }
        $clearCookie = $this->sessions->buildCookieHeader('', '1970-01-01 00:00:00');
        return Response::json(['ok' => true])->withHeader('Set-Cookie', $clearCookie);
    }

    /**
     * POST /api/auth/change-pin
     * Authenticated. Body: { currentPin, newPin, confirmPin }
     *
     * On success: all player sessions are terminated (including this one),
     * the cookie is cleared, and the client should redirect to login.
     */
    public function changePassword(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }

        $v = new Validator($request->json());
        $current = $v->requireString('currentPin', 1, 100);
        $new     = $v->requirePin('newPin');
        $confirm = $v->requireString('confirmPin', 1, 100);

        if ($new !== $confirm) {
            throw new HttpException(422, 'pin_mismatch', 'New PIN and confirmation do not match.');
        }

        $result = $this->passwordChange->change(
            $playerId,
            $current,
            $new,
            $request->clientIp,
            $request->userAgent,
        );

        // All sessions just got terminated, including the one that made this
        // request. Clear the cookie so the browser doesn't keep sending the
        // now-invalid token.
        $clearCookie = $this->sessions->buildCookieHeader('', '1970-01-01 00:00:00');

        return Response::json([
            'ok'                 => true,
            'sessionsTerminated' => $result['sessionsTerminated'],
            'message'            => 'PIN updated. Please sign in with your new PIN.',
        ])->withHeader('Set-Cookie', $clearCookie);
    }

    /**
     * POST /api/auth/forgot/lookup
     * Body: { phone }
     *
     * Public (no auth). Always returns the same shape regardless of whether
     * the phone is registered, to prevent account enumeration. Tells the
     * user to dial *920*995# to receive a code on their MoMo SIM.
     */
    public function forgotLookup(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone = $v->requirePhone('phone');

        $result = $this->forgotPassword->lookup($phone);

        return Response::json([
            'ok'         => true,
            'ussdCode'   => $result['ussdCode'],
            'ttlMinutes' => $result['ttlMinutes'],
            'message'    => 'If your number is registered, dial ' . $result['ussdCode'] . ' to get a 4-character code.',
        ]);
    }

    /**
     * POST /api/auth/forgot/reset
     * Body: { phone, code, newPin, confirmPin }
     *
     * Public (no auth). Verifies the OTP code, sets the new PIN,
     * terminates all existing sessions, and issues a fresh session for
     * THIS device (auto-login).
     */
    public function forgotReset(Request $request, array $params): Response
    {
        $v = new Validator($request->json());
        $phone   = $v->requirePhone('phone');
        $code    = $v->requireOtpCode('code', 4);
        $new     = $v->requirePin('newPin');
        $confirm = $v->requireString('confirmPin', 1, 100);

        if ($new !== $confirm) {
            throw new HttpException(422, 'pin_mismatch', 'New PIN and confirmation do not match.');
        }

        $session = $this->forgotPassword->reset(
            $phone,
            $code,
            $new,
            $request->clientIp,
            $request->userAgent,
        );

        $cookieHeader = $this->sessions->buildCookieHeader($session['token'], $session['expiresAt']);

        return Response::json([
            'ok'        => true,
            'playerId'  => $session['playerId'],
            'phone'     => $phone,
            'expiresAt' => $session['expiresAt'],
            'message'   => 'PIN reset. You are signed in.',
        ])->withHeader('Set-Cookie', $cookieHeader);
    }
}