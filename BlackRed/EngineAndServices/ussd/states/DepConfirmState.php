<?php
/**
 * DepConfirmState
 *
 *   Deposit GHS 20.00
 *   From: 0245399268 (MTN)
 *   Current Bal: GHS 35.00
 *   --
 *   1. Confirm
 *   2. Cancel
 *
 * On Confirm:
 *   - Run depositPreflight() synchronously (validates, inserts row)
 *   - END the USSD session immediately with "MoMo prompt is on the way"
 *   - Queue a post-response task to fire the ANM call after 3s
 *
 * On Cancel: END "Deposit cancelled."
 *
 * Why split? ANM/MoMo can't push a PIN prompt while the user's USSD
 * session is still open. We have to close USSD first, wait, then fire ANM.
 * See lib/deposit.php for the full rationale.
 *
 * Reads from session.data:
 *   - depAmountPesewas  (set by DEP_ENTER_AMOUNT)
 * Reads from session:
 *   - playerId          (set by ENTRY)
 *   - msisdn            (set on first turn)
 */

function render_DEP_CONFIRM(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $amountPesewas = (int)($session['data']['depAmountPesewas'] ?? 0);
    if ($amountPesewas <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    // Current PLAY balance for the confirm screen
    try {
        $bal = playerBalances($session['playerId']);
        $balLine = "Current Bal: " . formatPesewas($bal['play']);
    } catch (Throwable $e) {
        ussdLog('DEP_CONFIRM_BAL_ERROR', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        $balLine = "Current Bal: --";
    }

    // Provider for the display
    try {
        $player = playerById($session['playerId']);
        $provider = $player['paymentProvider'] ?? '?';
    } catch (Throwable) {
        $provider = '?';
    }

    $msg = "Deposit " . formatPesewas($amountPesewas) . "\n"
         . "From: " . displayMsisdn($session['msisdn']) . " ($provider)\n"
         . $balLine . "\n"
         . "--\n"
         . "1. Confirm\n"
         . "2. Cancel";
    return respStay($msg);
}

function handle_DEP_CONFIRM(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '2') {
        unset($session['data']['depAmountPesewas']);
        return respEnd("Deposit cancelled.");
    }

    if ($choice !== '1') {
        return respStay(
            "Invalid choice.\n"
            . "1. Confirm\n"
            . "2. Cancel"
        );
    }

    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $amountPesewas = (int)($session['data']['depAmountPesewas'] ?? 0);
    if ($amountPesewas <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // PHASE 1 — synchronous preflight. Insert the depositRequest row, get an id.
    try {
        $pre = depositPreflight($session['playerId'], $amountPesewas, $clientIp);
    } catch (InvalidArgumentException $e) {
        // Amount range (shouldn't reach here — DEP_ENTER_AMOUNT validates)
        return respEnd($e->getMessage());
    } catch (RuntimeException $e) {
        // Player not eligible / pending deposit / etc — show the reason and END
        return respEnd($e->getMessage());
    } catch (Throwable $e) {
        ussdLog('DEP_PREFLIGHT_UNEXPECTED', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        return respEnd("Deposit Failed. Please Try Again Later.");
    }

    unset($session['data']['depAmountPesewas']);

    // END the USSD session AND queue the ANM call for after the response is sent.
    // index.php will run depositFireAnm($depositId) after a 3-second wait.
    return respEndWithTask(
        "MoMo prompt is on the way.\n"
        . "--\n"
        . "Approve to deposit\n"
        . formatPesewas($amountPesewas) . "\n"
        . "to Your BlackRed Account.\n"
        . "You will get an SMS.",
        'deposit_anm_call',
        ['depositId' => $pre['depositId']]
    );
}
