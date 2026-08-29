<?php
/**
 * WdMomoConfirmState
 *
 *   Send GHS 20.00
 *   To: 0245399268 (MTN)
 *   ---
 *   1. Confirm
 *   2. Cancel
 *
 * Shows the destination MoMo number explicitly so the user can see where
 * the money is going. The number is always their REGISTERED MoMo number
 * (we don't allow custom destinations — Q3 answer).
 *
 * Input 1 → WD_MOMO_PASSWORD (final auth step)
 * Input 2 → END "Withdrawal cancelled"
 */

function render_WD_MOMO_CONFIRM(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $amountPesewas = (int)($session['data']['wdAmountPesewas'] ?? 0);
    if ($amountPesewas <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    // Show provider for transparency
    try {
        $player = playerById($session['playerId']);
        $provider = $player['paymentProvider'] ?? '?';
    } catch (Throwable) {
        $provider = '?';
    }

    $msg = "Send " . formatPesewas($amountPesewas) . "\n"
         . "To: " . displayMsisdn($session['msisdn']) . " ($provider)\n"
         . "---\n"
         . "1. Confirm\n"
         . "2. Cancel";
    return respStay($msg);
}

function handle_WD_MOMO_CONFIRM(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '2') {
        unset($session['data']['wdAmountPesewas']);
        return respEnd("Withdrawal cancelled.");
    }

    if ($choice !== '1') {
        return respStay(
            "Invalid choice.\n"
            . "1. Confirm\n"
            . "2. Cancel"
        );
    }

    // Reset pw attempt counter for next state
    $session['data']['wdPwAttempts'] = 0;
    return respNext('WD_MOMO_PASSWORD');
}
