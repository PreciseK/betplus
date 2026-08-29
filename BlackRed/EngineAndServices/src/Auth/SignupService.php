<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Integrations\AnmClient;
use BlackRed\Integrations\AnmLookupException;
use BlackRed\Logging\Logger;

/**
 * SignupService — coordinates the 3-step signup wizard.
 *
 * Steps:
 *   1. lookup(phone, network)   — calls ANM AII, caches name in signupSession
 *   2. verifyOtp(phone, code)   — checks code against authtoken table, marks step 2 done
 *   3. complete(phone, password) — creates player + accounts + wallets atomically
 *
 * State machine: each step requires the previous one to be done within the
 * same signupSession row (keyed by msisdn, lifespan ~30 minutes).
 *
 * If the user abandons mid-flow and retries later, we wipe the prior
 * signupSession and start over.
 */
final class SignupService
{
    /** Whole-flow lifetime — user has to finish signup within this window. */
    private const SESSION_TTL_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly Connection $db,
        private readonly AnmClient $anm,
        private readonly AuthtokenVerifier $authtoken,
        private readonly PasswordHasher $passwords,
        private readonly Logger $logger,
        private readonly OtpService $otp,
    ) {
    }

    /**
     * Step 1: Phone + network → lookup name via ANM, cache in signupSession.
     *
     * We don't enforce a strict prefix-to-network match here. The phone just
     * needs to look like a Ghana mobile (02X or 05X). The user's network
     * selection is sent to ANM as `bank_code`; ANM authoritatively answers
     * whether that number has an account on that network. This handles
     * number portability and avoids us shipping outdated prefix tables.
     *
     * @return array{phone: string, provider: string, registeredName: string, cached: bool}
     * @throws HttpException on conflict (already-registered phone)
     * @throws AnmLookupException on ANM failure (caller maps to user-friendly modal)
     */
    public function lookup(string $phoneCanonical, string $provider, string $ip): array
    {
        // Block lookup if the phone is already a registered player.
        $existing = $this->db->fetchOne(
            'SELECT id FROM player WHERE msisdn = :m AND deletedAt IS NULL LIMIT 1',
            ['m' => $phoneCanonical]
        );
        if ($existing !== null) {
            throw HttpException::conflict(
                'phone_already_registered',
                'This phone number is already registered. Try logging in instead.'
            );
        }

        $phoneLocal = '0' . substr($phoneCanonical, 3);

        // Call ANM. Exception bubbles up — controller catches and returns 4xx with
        // a user-friendly modal message. ANM is authoritative on whether the
        // number is registered on the chosen network.
        $result = $this->anm->lookupName($phoneLocal, $provider);
        $name = $result['name'];

        // Persist signupSession state. If a previous unconsumed session exists for
        // this phone, replace it (treating step 1 as a restart).
        $this->db->transactional(function (Connection $db) use ($phoneCanonical, $provider, $name, $result, $ip) {
            $db->execute(
                'DELETE FROM signupSession WHERE msisdn = :m AND consumedAt IS NULL',
                ['m' => $phoneCanonical]
            );
            $db->execute(
                'INSERT INTO signupSession
                    (msisdn, paymentProvider, registeredName, nameLookupRaw,
                     lookupCompletedAt, ipAddress, expiresAt, createdAt)
                 VALUES
                    (:m, :p, :n, :raw, NOW(), :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())',
                [
                    'm'   => $phoneCanonical,
                    'p'   => $provider,
                    'n'   => $name,
                    'raw' => json_encode($result['raw']),
                    'ip'  => $ip ?: null,
                    'ttl' => self::SESSION_TTL_SECONDS,
                ]
            );
        });

        $this->logger->info('signup_step1_lookup_ok', [
            'phone' => $phoneCanonical,
            'provider' => $provider,
            'cached' => $result['cached'],
        ]);

        // Issue an OTP and dispatch it via SMS (with USSD *920*995# as fallback
        // since OtpService writes codePlain that USSD selects). SMS failure
        // is logged but doesn't fail the lookup — the user still has USSD.
        $otpResult = $this->otp->issue($phoneCanonical, 'signup');

        return [
            'phone'          => $phoneCanonical,
            'provider'       => $provider,
            'registeredName' => $name,
            'cached'         => $result['cached'],
            'smsSent'        => $otpResult['smsSent'],
            'otpExpiresAt'   => $otpResult['expiresAt'],
        ];
    }

    /**
     * Step 2: Verify the OTP code against the authtoken table populated by USSD.
     *
     * @throws HttpException on missing prerequisite or invalid code
     */
    public function verifyOtp(string $phoneCanonical, string $code): void
    {
        $session = $this->loadActiveSession($phoneCanonical);
        if ($session === null) {
            throw HttpException::badRequest(
                'signup_session_missing',
                'Please start signup from the beginning. Your session may have expired.'
            );
        }
        if ($session['lookupCompletedAt'] === null) {
            throw HttpException::badRequest(
                'lookup_required_first',
                'Please complete the name lookup step first.'
            );
        }

        if (!$this->authtoken->verify($phoneCanonical, $code)) {
            throw HttpException::badRequest(
                'invalid_or_expired_code',
                'The code is incorrect or has expired. Please dial the USSD again to get a new one.'
            );
        }

        $this->db->execute(
            'UPDATE signupSession SET otpVerifiedAt = NOW() WHERE id = :id',
            ['id' => $session['id']]
        );

        $this->logger->info('signup_step2_otp_verified', ['phone' => $phoneCanonical]);
    }

    /**
     * Step 3: Set password, create the player + accounts + wallets atomically.
     *
     * @return int  The newly created player ID
     * @throws HttpException on missing prerequisites or concurrent registration
     */
    public function complete(string $phoneCanonical, string $password): int
    {
        $session = $this->loadActiveSession($phoneCanonical);
        if ($session === null) {
            throw HttpException::badRequest(
                'signup_session_missing',
                'Please start signup from the beginning. Your session may have expired.'
            );
        }
        if ($session['lookupCompletedAt'] === null) {
            throw HttpException::badRequest(
                'lookup_required_first',
                'Name lookup not completed. Please restart signup.'
            );
        }
        if ($session['otpVerifiedAt'] === null) {
            throw HttpException::badRequest(
                'otp_required_first',
                'OTP not verified. Please complete the USSD step.'
            );
        }

        // Final defensive check — has someone snuck in and created this player
        // between step 2 and step 3?
        $exists = $this->db->fetchOne(
            'SELECT id FROM player WHERE msisdn = :m AND deletedAt IS NULL LIMIT 1',
            ['m' => $phoneCanonical]
        );
        if ($exists !== null) {
            throw HttpException::conflict(
                'phone_already_registered',
                'This phone number was registered while your signup was in flight. Please log in.'
            );
        }

        $passwordHash = $this->passwords->hash($password);

        $playerId = $this->createPlayer(
            $phoneCanonical,
            (string)$session['paymentProvider'],
            (string)$session['registeredName'],
            $passwordHash,
            null, // email — not collected in this flow
        );

        // Mark the signupSession as consumed
        $this->db->execute(
            'UPDATE signupSession SET consumedAt = NOW() WHERE id = :id',
            ['id' => $session['id']]
        );

        $this->logger->info('signup_step3_completed', [
            'phone'     => $phoneCanonical,
            'player_id' => $playerId,
        ]);

        return $playerId;
    }

    /**
     * Convenience: load the active (unexpired, unconsumed) signupSession for a phone.
     * Returns null if none exists.
     */
    private function loadActiveSession(string $phoneCanonical): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, msisdn, paymentProvider, registeredName,
                    lookupCompletedAt, otpVerifiedAt, expiresAt, consumedAt
             FROM signupSession
             WHERE msisdn = :m
               AND consumedAt IS NULL
               AND expiresAt > NOW()
             ORDER BY id DESC LIMIT 1',
            ['m' => $phoneCanonical]
        );
    }

    /**
     * Create the player + their two accounts (PLAY, PAYOUT) + their two
     * wallets, atomically.
     *
     * We do the inserts directly rather than calling the stored procedure
     * because PDO + MySQL stored procedures behave inconsistently on shared
     * hosting: some drivers throw on nextRowset() when a procedure returns
     * no result sets, others don't. The direct-insert path is portable and
     * easier to reason about.
     *
     * The transactional() wrapper gives us all-or-nothing atomicity plus
     * automatic deadlock retry, which is what we wanted from the procedure
     * in the first place.
     */
    private function createPlayer(
        string $msisdn,
        string $provider,
        string $registeredName,
        string $passwordHash,
        ?string $email,
    ): int {
        return $this->db->transactional(function (Connection $db) use (
            $msisdn,
            $provider,
            $registeredName,
            $passwordHash,
            $email,
        ): int {
            // 1. Insert player
            $playerId = $db->insert(
                'INSERT INTO player
                    (msisdn, paymentProvider, registeredName, passwordHash, email,
                     kycStatus, accountStatus, registrationChannel, createdAt, updatedAt)
                 VALUES
                    (:msisdn, :prov, :name, :phash, :email,
                     :kyc, :status, :channel, NOW(), NOW())',
                [
                    'msisdn'  => $msisdn,
                    'prov'    => $provider,
                    'name'    => $registeredName,
                    'phash'   => $passwordHash,
                    'email'   => $email,
                    'kyc'     => 'pending',
                    'status'  => 'active',
                    'channel' => 'web',
                ]
            );

            // 2. Insert PLAY account
            $playAccountId = $db->insert(
                'INSERT INTO account
                    (accountCode, accountType, ownerType, ownerId, currency,
                     normalBalance, status, createdAt, updatedAt)
                 VALUES
                    (:code, :type, :owner, :ownerId, :ccy,
                     :nb, :status, NOW(), NOW())',
                [
                    'code'    => 'PLAYER_PLAY:' . $playerId,
                    'type'    => 'PLAYER_PLAY',
                    'owner'   => 'player',
                    'ownerId' => $playerId,
                    'ccy'     => 'GHS',
                    'nb'      => 'credit', // player wallets are platform liabilities (credit-normal per migration 005)
                    'status'  => 'active',
                ]
            );

            // 3. Insert PAYOUT account
            $payoutAccountId = $db->insert(
                'INSERT INTO account
                    (accountCode, accountType, ownerType, ownerId, currency,
                     normalBalance, status, createdAt, updatedAt)
                 VALUES
                    (:code, :type, :owner, :ownerId, :ccy,
                     :nb, :status, NOW(), NOW())',
                [
                    'code'    => 'PLAYER_PAYOUT:' . $playerId,
                    'type'    => 'PLAYER_PAYOUT',
                    'owner'   => 'player',
                    'ownerId' => $playerId,
                    'ccy'     => 'GHS',
                    'nb'      => 'credit', // player wallets are platform liabilities (credit-normal per migration 005)
                    'status'  => 'active',
                ]
            );

            // 4. Insert PLAY wallet
            $db->execute(
                'INSERT INTO wallet
                    (playerId, walletType, accountId, cachedBalancePesewas, version,
                     status, createdAt, updatedAt)
                 VALUES
                    (:pid, :wtype, :aid, 0, 0, :status, NOW(), NOW())',
                [
                    'pid'    => $playerId,
                    'wtype'  => 'PLAY',
                    'aid'    => $playAccountId,
                    'status' => 'active',
                ]
            );

            // 5. Insert PAYOUT wallet
            $db->execute(
                'INSERT INTO wallet
                    (playerId, walletType, accountId, cachedBalancePesewas, version,
                     status, createdAt, updatedAt)
                 VALUES
                    (:pid, :wtype, :aid, 0, 0, :status, NOW(), NOW())',
                [
                    'pid'    => $playerId,
                    'wtype'  => 'PAYOUT',
                    'aid'    => $payoutAccountId,
                    'status' => 'active',
                ]
            );

            return $playerId;
        });
    }
}