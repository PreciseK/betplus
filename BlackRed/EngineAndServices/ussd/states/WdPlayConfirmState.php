<?php
/**
 * WdPlayConfirmState
 *
 *   Move GHS 20.00
 *   Payout 50→30
 *   Play   12→32
 *   --
 *   1. Confirm
 *   2. Cancel
 *
 * Shows projected before→after for both wallets so the user can see
 * exactly what'll happen.
 *
 * Input 1 → WD_PLAY_PASSWORD (final auth step)
 * Input 2 → END "Transfer cancelled"
 *
 * Display budget: USSD screens are tight. The "X→Y" form is shorter
 * than "from X to Y" and reads naturally.
 */

function render_WD_PLAY_CONFIRM(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $amountPesewas = (int)($session['data']['wdAmountPesewas'] ?? 0);
    if ($amountPesewas <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $msg = "Move " . formatPesewas($amountPesewas) . " To Your Play Balance\n"
         . "---\n"
         . "1. Confirm\n"
         . "2. Cancel";

    return respStay($msg);
}

function handle_WD_PLAY_CONFIRM(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '2') {
        unset($session['data']['wdAmountPesewas']);
        return respEnd("Transfer cancelled.");
    }

    if ($choice !== '1') {
        return respStay(
            "Invalid choice.\n"
            . "1. Confirm\n"
            . "2. Cancel"
        );
    }

    // Reset retry counter for the password step that comes next
    $session['data']['wdPwAttempts'] = 0;
    return respNext('WD_PLAY_PASSWORD');
}
