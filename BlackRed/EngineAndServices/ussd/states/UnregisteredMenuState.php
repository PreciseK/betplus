<?php
/**
 * UnregisteredMenuState
 *
 *   Hello [0244000001]
 *   Welcome to BlackRed
 *   ---
 *   1. Register
 *   2. Exit
 *
 * Input 1 → resolve bank_code from Nalo's network, call ANM lookup,
 *           store result in session, route to REG_CONFIRM_NAME.
 *           If ANM fails, END with the user-safe error from ANM.
 *           If Nalo gave us an unrecognised network, fall back to
 *           REG_PICK_PROVIDER (asks user to choose).
 * Input 2 → END
 * Other  → re-render with error
 */

function render_UNREGISTERED_MENU(array &$session): array
{
    $msisdnDisplay = displayMsisdn($session['msisdn']);
    $msg = "Hello $msisdnDisplay\n"
         . "Welcome to BlackRed\n"
         . "---\n"
         . "1. Register\n"
         . "2. Exit";
    return respStay($msg);
}

function handle_UNREGISTERED_MENU(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '2') {
        return respEnd("Goodbye.");
    }

    if ($choice !== '1') {
        return respStay(
            "Invalid choice. Try again:\n"
            . "1. Register\n"
            . "2. Exit"
        );
    }

    // ── User picked Register. Skip provider question — use Nalo's network. ──
    $naloNetwork = (string)($session['network'] ?? '');
    $bankCode = anmNetworkToBankCode($naloNetwork);

    if ($bankCode === null) {
        // Nalo didn't give us a recognisable network. Fall back to asking.
        ussdLog('REG_UNKNOWN_NETWORK', [
            'msisdn'      => $session['msisdn'],
            'naloNetwork' => $naloNetwork,
        ]);
        return respNext('REG_PICK_PROVIDER');
    }

    // Look up name on the user's actual SIM network
    $phoneLocal = displayMsisdn($session['msisdn']);

    try {
        $name = anmNameLookup($phoneLocal, $bankCode);
    } catch (RuntimeException $e) {
        ussdLog('REG_LOOKUP_FAILED', [
            'msisdn'    => $session['msisdn'],
            'bank_code' => $bankCode,
            'error'     => $e->getMessage(),
        ]);
        // Show user the error message ANM gave us
        return respEnd($e->getMessage() . "\nPlease try again later.");
    }

    // Stash for REG_CONFIRM_NAME. Use ANM bank_code internally; we'll
    // convert to the schema's provider enum (MTN | TEL | ATL) only at
    // playerCreate() time so any DB column constraint is satisfied.
    $session['data']['regBankCode'] = $bankCode;
    $session['data']['regName']     = $name;

    return respNext('REG_CONFIRM_NAME');
}
