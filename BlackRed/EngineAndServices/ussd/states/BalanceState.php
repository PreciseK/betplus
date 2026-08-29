<?php
/**
 * BalanceState
 *
 *   Hello [FirstName]
 *   Your Balances Are:
 *   ----
 *   Play Balance: GHS X.YY
 *   Payout Balance: GHS X.YY
 *
 * Terminal state — ENDs immediately. No handle() ever runs.
 */

function render_BALANCE(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $bal = playerBalances($session['playerId']);
    } catch (Throwable $e) {
        ussdLog('BALANCE_ERROR', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        return respEnd("Balance Temporarily Unavailable.\nPlease Try Again Later.");
    }

    $firstName = $session['data']['firstName'] ?? 'there';
    $msg = "Hello $firstName\n"
         . "Your Balances Are:\n"
         . "----\n"
         . "Play Balance: " . formatPesewas($bal['play']) . "\n"
         . "Payout Balance: " . formatPesewas($bal['payout']);

    return respEnd($msg);
}

function handle_BALANCE(string $input, array &$session): array
{
    return respEnd("Goodbye.");
}
