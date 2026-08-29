<?php
/**
 * MainMenuState
 *
 *   Hello [FirstName]
 *   Welcome to BlackRed
 *   ----
 *   1. Play Now
 *   2. Deposit
 *   3. Withdraw
 *   4. Check Balance
 *   5. Check Last Stake
 *
 * Inputs 1-3 are stubbed in PR 2 (they ENDs with "Coming soon").
 * Inputs 4 and 5 work end-to-end now.
 *
 * Note: this state assumes session['data']['firstName'] was set by
 * EntryState. If not (e.g. if a malformed session somehow lands here),
 * we fall back to "there".
 */

function render_MAIN_MENU(array &$session): array
{
    $firstName = $session['data']['firstName'] ?? 'there';
    $msg = "Hello $firstName\n"
         . "Welcome to BlackRed\n"
         . "----\n"
         . "1. Play Now\n"
         . "2. Deposit\n"
         . "3. Withdraw\n"
         . "4. Check Balance\n"
         . "5. Check Last Stake";
    return respStay($msg);
}

function handle_MAIN_MENU(string $input, array &$session): array
{
    $choice = trim($input);

    switch ($choice) {
        case '1':
            return respNext('PLAY_PICK_TYPE');

        case '2':
            return respNext('DEP_ENTER_AMOUNT');

        case '3':
            return respNext('WD_PICK_DESTINATION');

        case '4':
            return respNext('BALANCE');

        case '5':
            return respNext('LAST_STAKE');

        default:
            return respStay(
                "Invalid choice. Try again:\n"
                . "1. Play Now\n"
                . "2. Deposit\n"
                . "3. Withdraw\n"
                . "4. Check Balance\n"
                . "5. Check Last Stake"
            );
    }
}
