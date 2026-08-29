<?php
/**
 * RegConfirmPasswordState — confirm the password the user just set.
 *
 *   Confirm Your Password
 *   --
 *   Type the same
 *   password again
 *   and send:
 *
 * Input = candidate confirmation password
 *   - If matches → create player, send welcome SMS, END with greeting
 *   - If differs → END with mismatch message (user must restart registration)
 *
 * Why one-shot END on mismatch rather than retry:
 *   USSD turns are slow on multi-tap. Two re-types of a long password
 *   is painful UX. Bouncing the user back to REG_SET_PASSWORD invites
 *   confusion (they retype, mismatch again, etc.). Cleaner: tell them
 *   the passwords didn't match, END, they redial. Better than a
 *   confusing loop.
 *
 *   We DO catch the case where the user has typed an obviously different
 *   wrong-length password (e.g. they tapped "8" thinking it was a menu),
 *   so the message is gentle.
 *
 * Reads from session.data:
 *   - regBankCode  (set by UnregisteredMenu or RegPickProvider)
 *   - regName      (set by ANM lookup)
 *   - regPwHash    (set by RegSetPasswordState)
 *
 * After successful insert, clears all of the above.
 */

function render_REG_CONFIRM_PASSWORD(array &$session): array
{
    $msg = "Confirm Your PIN\n"
         . "--\n"
         . "Type the same\n"
         . "PIN again\n"
         . "and send:";
    return respStay($msg);
}

function handle_REG_CONFIRM_PASSWORD(string $input, array &$session): array
{
    $confirm = trim($input);  // PIN is numeric; trim defensively

    $storedHash = (string)($session['data']['regPwHash'] ?? '');
    if ($storedHash === '') {
        ussdLog('REG_NO_HASH_ON_CONFIRM', ['msisdn' => $session['msisdn'] ?? null]);
        return respEnd("Session error. Please dial *920*9# again.");
    }

    if (!verifyPassword($confirm, $storedHash)) {
        // Clear the stashed hash so the user redialing starts clean.
        unset($session['data']['regPwHash']);
        return respEnd(
            "PINs did not match.\n"
            . "Please dial *920*9# to try again."
        );
    }

    // Match — create the player
    $bankCode = (string)($session['data']['regBankCode'] ?? '');
    $name     = (string)($session['data']['regName'] ?? '');

    if ($bankCode === '' || $name === '') {
        ussdLog('REG_MISSING_DATA', [
            'naloSessionId' => $session['naloSessionId'] ?? null,
            'hasBankCode'   => $bankCode !== '',
            'hasName'       => $name !== '',
        ]);
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $provider = anmBankCodeToProvider($bankCode);
    } catch (InvalidArgumentException) {
        ussdLog('REG_BAD_BANKCODE', ['bankCode' => $bankCode]);
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $playerId = playerCreate($session['msisdn'], $provider, $name, $storedHash);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            ussdLog('REG_RACE_LOST', ['msisdn' => $session['msisdn']]);
            return respEnd("You are already registered.\nDial *920*9# to play.");
        }
        ussdLog('REG_DB_ERROR', [
            'msisdn' => $session['msisdn'],
            'error'  => $e->getMessage(),
        ]);
        return respEnd("Registration failed. Please try again later.");
    } catch (Throwable $e) {
        ussdLog('REG_FAILED', [
            'msisdn' => $session['msisdn'],
            'error'  => $e->getMessage(),
        ]);
        return respEnd("Registration failed. Please try again later.");
    }

    // Clear sensitive data from session
    unset(
        $session['data']['regBankCode'],
        $session['data']['regName'],
        $session['data']['regPwHash']
    );
    $session['playerId'] = $playerId;

    $firstName = explode(' ', trim($name))[0] ?? 'there';
    $firstName = ucfirst(strtolower($firstName));

    ussdLog('REG_SUCCESS', [
        'playerId' => $playerId,
        'msisdn'   => $session['msisdn'],
        'provider' => $provider,
    ]);

    // Send welcome SMS — best-effort, must not block on failure
    try {
        $smsSent = hubtelSendWelcomeSms($session['msisdn'], $firstName);
        ussdLog('REG_WELCOME_SMS', ['playerId' => $playerId, 'sent' => $smsSent]);
    } catch (Throwable $e) {
        // hubtelSendWelcomeSms shouldn't throw, but defensive.
        ussdLog('REG_WELCOME_SMS_THREW', ['playerId' => $playerId, 'err' => $e->getMessage()]);
    }

    return respEnd(
        "Welcome $firstName!\n"
        . "--\n"
        . "Registration complete.\n"
        . "Dial *920*9# to play."
    );
}
