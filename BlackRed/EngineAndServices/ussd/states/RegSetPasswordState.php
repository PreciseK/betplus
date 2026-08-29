<?php
/**
 * RegSetPasswordState — first of two password screens.
 *
 *   Set Your Password
 *   --
 *   Must have:
 *   8+ chars, capital,
 *   number, symbol.
 *   Type and send:
 *
 * Input = candidate password
 *   - If valid policy → stash hash in session, route to REG_CONFIRM_PASSWORD
 *   - If invalid      → show specific reason, retry (session stays here)
 *
 * Why we hash here rather than passing plaintext between turns:
 *   Storing plaintext in session.data even briefly is a footgun. It would
 *   land in the ussdSession.data JSON column on disk. Hashing immediately
 *   means the worst-case disk leak only exposes the hash, not the password.
 *   The user types the same password again on the confirm screen; we hash
 *   THAT and compare hashes — same result as comparing plaintext, never
 *   stores plaintext.
 *
 *   Caveat: Argon2id includes a random salt, so two hashes of the same
 *   password will differ in bytes. We use password_verify($confirmInput,
 *   $storedHash) to do the comparison correctly. That's exactly what the
 *   login flow does too — same primitive, same guarantees.
 */

function render_REG_SET_PASSWORD(array &$session): array
{
    $msg = "Set Your PIN\n"
         . "--\n"
         . "Enter a 4-digit\n"
         . "PIN for your\n"
         . "account.\n"
         . "Type and send:";
    return respStay($msg);
}

function handle_REG_SET_PASSWORD(string $input, array &$session): array
{
    $pin = trim($input);  // PIN is numeric; trim whitespace defensively

    $err = validatePassword($pin);
    if ($err !== null) {
        return respStay(
            $err . "\n"
            . "--\n"
            . "Type and send again:"
        );
    }

    // Hash now so plaintext never lands on disk in session.data.
    try {
        $session['data']['regPwHash'] = hashPassword($pin);
    } catch (Throwable $e) {
        ussdLog('REG_HASH_FAILED', ['error' => $e->getMessage()]);
        return respEnd("Registration failed. Please try again later.");
    }

    return respNext('REG_CONFIRM_PASSWORD');
}
