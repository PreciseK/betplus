<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;

/**
 * AuthtokenVerifier — verifies a 4-digit OTP entered in the app against
 * codes issued by OtpService (which writes to authtoken with both the
 * SHA-512 hash and the plaintext code).
 *
 * Schema (new columns added by the PIN/OTP migration):
 *   authtoken (
 *     id INT,
 *     phonenumber VARCHAR(20),       -- "0244XXXXXX" (10 digits, leading 0)
 *     codehash VARCHAR(255),         -- hash('sha512', $code)
 *     codePlain VARCHAR(10) NULL,    -- plaintext for USSD retrieval
 *     expirydate DATE,               -- legacy, still populated for backwards compat
 *     expirytime TIME,               -- legacy
 *     expiresAt DATETIME,            -- canonical expiry, used in queries
 *     purpose ENUM('signup','login','pin_reset'),
 *     consumedAt DATETIME NULL,      -- set on successful verify; prevents replay
 *     ...
 *   )
 *
 * Verification:
 *   1. Find the row where phone matches, expiresAt > NOW(), consumedAt IS NULL.
 *   2. Constant-time compare submitted code's hash with stored codehash.
 *   3. On success: mark consumedAt = NOW() so the same code can't be used twice.
 *
 * One-time use is enforced via consumedAt, not via DELETE — keeps the row
 * around for audit trail and lets reports see what was verified when.
 *
 * Security: a 4-digit numeric code has 10,000 possible values. Brute force
 * is mitigated by the per-account lockout (3 wrong PIN/OTP attempts => 15
 * min lock on player.lockedUntil) which is enforced by AuthController on
 * the verification calls.
 */
final class AuthtokenVerifier
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Verify a code against the active token for the phone.
     *
     * @param string $phoneCanonical 233XXXXXXXXX form (12 digits)
     * @param string $code           User-entered code (digits only)
     * @return bool                  true on match, false otherwise
     */
    public function verify(string $phoneCanonical, string $code): bool
    {
        $phoneLocal = $this->canonicalToLocal($phoneCanonical);
        if ($phoneLocal === null) {
            return false;
        }

        // Sanity-check shape. Don't bother with DB query for obviously-bad input.
        $code = trim($code);
        if ($code === '' || strlen($code) > 32) {
            return false;
        }

        $hash = hash('sha512', $code);

        // Find the active (unconsumed, unexpired) token for this phone.
        $row = $this->db->fetchOne(
            'SELECT id, codehash
               FROM authtoken
              WHERE phonenumber = :phone
                AND consumedAt IS NULL
                AND expiresAt > NOW()
              ORDER BY id DESC LIMIT 1',
            ['phone' => $phoneLocal]
        );

        if ($row === null) {
            return false;
        }

        // Constant-time compare.
        if (!hash_equals((string)$row['codehash'], $hash)) {
            return false;
        }

        // Mark consumed. Don't DELETE — we want the audit trail.
        // (Old plaintext is still in codePlain; the next OTP issue for this
        // phone will DELETE the row entirely as part of its clean-slate.)
        $this->db->execute(
            'UPDATE authtoken SET consumedAt = NOW() WHERE id = :id',
            ['id' => (int)$row['id']]
        );

        return true;
    }

    /**
     * Convert canonical 233XXXXXXXXX => local 0XXXXXXXXX form for the lookup.
     */
    private function canonicalToLocal(string $canonical): ?string
    {
        if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
            return null;
        }
        return '0' . substr($canonical, 3);
    }
}
