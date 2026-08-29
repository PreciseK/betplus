<?php
/**
 * PIN handling for USSD.
 *
 * Policy (changed from 8-char password to 4-digit PIN per product decision):
 *   - Exactly 4 digits, no other characters
 *   - No forbidden-PIN list (per product spec: accept any 4 digits)
 *
 * Storage: hashed with Argon2id and stored in `player.passwordHash`. We
 * deliberately reuse that column rather than `pinHash` so the web app and
 * USSD share one credential — same hash, verifiable from either channel.
 *
 * The function names below keep "Password" in them because the rest of
 * the codebase calls them by those names. Renaming would cascade into
 * every state file and the web app. The implementation underneath is
 * what changed; the contract didn't.
 *
 * Verification: password_verify() handles Argon2id and any future algo
 * changes automatically, so this stays forward-compatible with the web app.
 *
 * Security note on 4-digit PINs:
 *   A 4-digit PIN has 10,000 possible values. By itself that's trivially
 *   brute-forceable. The defence is the per-account lockout (see
 *   lib/player.php :: playerLockoutCheck / playerLockoutBump) that
 *   blocks the account after 3 wrong attempts for 15 minutes. Same pool
 *   is shared with the web app via player.failedLoginCount + lockedUntil.
 */

/**
 * Validate PIN against the policy. Returns null if valid, or a short
 * user-facing error message if not (suitable for a USSD screen).
 */
function validatePassword(string $pin): ?string
{
    // Exactly 4 chars, all digits. PIN should never have spaces, etc.
    if (strlen($pin) !== 4) {
        return "PIN must be exactly 4 digits.";
    }
    if (!preg_match('/^\d{4}$/', $pin)) {
        return "PIN must be 4 digits, no letters.";
    }
    return null;
}

/**
 * Hash a plaintext PIN. Returns the encoded hash string ready to store
 * in player.passwordHash.
 *
 * Throws if hashing fails (very rare — usually means PHP was built without
 * Argon2 support, which would be a deployment problem to fix).
 */
function hashPassword(string $plaintext): string
{
    if ($plaintext === '') {
        throw new RuntimeException('Cannot hash an empty PIN');
    }
    $hash = password_hash($plaintext, PASSWORD_ARGON2ID);
    if ($hash === false) {
        throw new RuntimeException('PIN hashing failed');
    }
    return $hash;
}

/**
 * Verify a plaintext PIN against a stored hash. Constant-time via
 * password_verify(), defeats timing attacks.
 */
function verifyPassword(string $plaintext, string $hash): bool
{
    if ($plaintext === '' || $hash === '') return false;
    return password_verify($plaintext, $hash);
}
