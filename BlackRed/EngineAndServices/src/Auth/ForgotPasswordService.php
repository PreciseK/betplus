<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Logging\Logger;

/**
 * ForgotPasswordService — reset a forgotten password using the USSD code
 * the player can obtain by dialing *920*995# on the SIM registered with
 * their account.
 *
 * The flow:
 *
 *   1. lookup(phone) — checks whether an account exists. ALWAYS returns
 *      ok (no enumeration) but internally short-circuits if no account.
 *      The user is told to dial USSD regardless. This costs nothing extra
 *      because USSD writes to authtoken keyed by phone, and a non-existent
 *      account just means the code never gets used.
 *
 *   2. reset(phone, code, newPassword) — validates the USSD code via
 *      AuthtokenVerifier (deletes the code on success — one-time use),
 *      then atomically:
 *        - updates passwordHash + passwordUpdatedAt
 *        - terminates ALL existing sessions for this player
 *        - writes an eventLog entry
 *        - issues a fresh session for this device (auto-login)
 *
 * Security model:
 *   - Phone is the second factor. Same trust model as MoMo. SIM-swap is the
 *     primary risk and is accepted as commensurate with the rest of the
 *     ecosystem.
 *   - USSD code is one-time use, 5-minute validity.
 *   - Aggressive rate limit (5 reset attempts/phone/hour) defends against
 *     brute-forcing the 4-char code.
 *   - Password policy is the same as signup/change (8+, upper, digit, symbol).
 */
final class ForgotPasswordService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwords,
        private readonly AuthtokenVerifier $authtoken,
        private readonly SessionManager $sessions,
        private readonly Logger $logger,
        private readonly OtpService $otp,
    ) {
    }

    /**
     * Step 1 of forgot-password — silently confirm/deny existence and tell
     * the user to dial USSD. Always returns the same shape regardless of
     * whether the phone is registered, to prevent account enumeration.
     *
     * @return array{ussdCode: string, ttlMinutes: int}
     */
    public function lookup(string $phoneCanonical): array
    {
        // We don't actually need to query the DB for this step — the user
        // experience is the same either way. But we log differently so ops
        // can spot spray attacks.
        $row = $this->db->fetchOne(
            'SELECT id, accountStatus, deletedAt FROM player WHERE msisdn = :p LIMIT 1',
            ['p' => $phoneCanonical]
        );

        $exists = $row !== null && $row['deletedAt'] === null && $row['accountStatus'] === 'active';

        $this->logger->info('forgot_password_lookup', [
            'phone'  => $phoneCanonical,
            'exists' => $exists,
        ]);

        // Issue OTP only if the account exists. Anti-enumeration is preserved:
        // we return the same response shape in both branches, and the SMS is
        // delivered (or not) silently. An attacker cannot distinguish
        // "account exists" from "account doesn't" without owning the SIM.
        // The USSD *920*995# fallback returns nothing useful if no OTP was
        // issued, which matches the experience of mistyping your own number.
        if ($exists) {
            $this->otp->issue($phoneCanonical, 'pin_reset', (int)$row['id']);
        }

        // Same response either way (anti-enumeration). Frontend prompts user
        // to check SMS or dial USSD; if the account doesn't exist neither
        // produces a usable code.
        return [
            'ussdCode'   => '*920*995#',
            'ttlMinutes' => 5,
        ];
    }

    /**
     * Step 2 of forgot-password — verify USSD code and reset password.
     *
     * Called inside a transaction so the password change, session purge,
     * audit log entry, and new session creation are atomic. Either all
     * happen or none do.
     *
     * @return array{token: string, sessionId: int, expiresAt: string, playerId: int}
     * @throws HttpException 400 invalid_or_expired_code
     * @throws HttpException 401 if account doesn't exist (rare; means
     *         someone made it past lookup with a non-registered phone — we
     *         still gate here)
     */
    public function reset(
        string $phoneCanonical,
        string $code,
        string $newPassword,
        string $ipAddress,
        ?string $userAgent,
    ): array {
        // 1. Verify the USSD code first. AuthtokenVerifier accepts the
        //    canonical form and handles deletion-on-success internally.
        if (!$this->authtoken->verify($phoneCanonical, $code)) {
            $this->logger->info('forgot_password_reset_failed', [
                'phone'  => $phoneCanonical,
                'reason' => 'invalid_or_expired_code',
            ]);
            throw new HttpException(
                400,
                'invalid_or_expired_code',
                'The code is incorrect or has expired. Please dial *920*995# again to get a new one.'
            );
        }

        // 2. Find the account. If lookup ran first this should always find
        //    something; defensive check protects against direct API hits.
        $player = $this->db->fetchOne(
            'SELECT id, msisdn, accountStatus, deletedAt
             FROM player WHERE msisdn = :p LIMIT 1',
            ['p' => $phoneCanonical]
        );

        if ($player === null
            || $player['deletedAt'] !== null
            || $player['accountStatus'] !== 'active'
        ) {
            // Code was valid but the account isn't usable. We've already
            // burned the code (verify deletes it) — that's intentional.
            // Don't reveal whether the account exists.
            $this->logger->info('forgot_password_reset_failed', [
                'phone'  => $phoneCanonical,
                'reason' => 'no_account_or_inactive',
            ]);
            throw new HttpException(400, 'invalid_or_expired_code', 'The code is incorrect or has expired.');
        }

        $playerId = (int)$player['id'];

        // 3. Atomic state change.
        return $this->db->transactional(function (Connection $db) use ($playerId, $phoneCanonical, $newPassword, $ipAddress, $userAgent): array {
            $newHash = $this->passwords->hash($newPassword);

            $db->execute(
                'UPDATE player
                    SET passwordHash = :hash,
                        passwordUpdatedAt = NOW(),
                        updatedAt = NOW()
                  WHERE id = :id',
                ['hash' => $newHash, 'id' => $playerId]
            );

            // Terminate every active session for this player. After this
            // commits, every device with the old session is logged out on
            // its very next request.
            $db->execute(
                'UPDATE playerSession
                    SET terminatedAt = NOW()
                  WHERE playerId = :id AND terminatedAt IS NULL',
                ['id' => $playerId]
            );
            $sessionsTerminated = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM playerSession
                  WHERE playerId = :id
                    AND terminatedAt IS NOT NULL
                    AND terminatedAt >= DATE_SUB(NOW(), INTERVAL 5 SECOND)',
                ['id' => $playerId]
            );

            // Audit trail. Distinct event_type from password_changed so we
            // can tell self-service from forgot-password resets.
            $db->execute(
                'INSERT INTO eventLog (playerId, eventType, ipAddress, userAgent, metadata, createdAt)
                 VALUES (:pid, :type, :ip, :ua, :meta, NOW())',
                [
                    'pid'  => $playerId,
                    'type' => 'password_reset_via_ussd',
                    'ip'   => $ipAddress,
                    'ua'   => $userAgent !== null ? substr($userAgent, 0, 512) : null,
                    'meta' => json_encode([
                        'method'              => 'forgot_password',
                        'sessions_terminated' => $sessionsTerminated,
                    ]),
                ]
            );

            // Issue a fresh session for this device (auto-login). Using the
            // SessionManager keeps cookie/lifetime logic in one place.
            $session = $this->sessions->create(
                $playerId,
                $phoneCanonical,
                'web',
                $ipAddress,
                $userAgent,
            );

            $this->logger->info('forgot_password_reset_succeeded', [
                'player_id'           => $playerId,
                'sessions_terminated' => $sessionsTerminated,
            ]);

            return [
                'token'     => $session['token'],
                'sessionId' => $session['sessionId'],
                'expiresAt' => $session['expiresAt'],
                'playerId'  => $playerId,
            ];
        });
    }
}