<?php
/**
 * WdPickDestinationState
 *
 *   Withdraw to:
 *   --
 *   1. Play Balance
 *   2. MoMo (Coming Soon)
 *
 * Input 1 → WD_PLAY_ENTER_AMOUNT
 * Input 2 → END (coming soon stub)
 * Other  → re-render with error
 *
 * Frame: this is the destination picker for ALL withdrawals. When MoMo
 * lands (Path 2 in this PR), option 2 will route to WD_MOMO_ENTER_AMOUNT
 * instead of ENDing.
 */

function render_WD_PICK_DESTINATION(array &$session): array
{
    $msg = "Withdraw to:\n"
         . "--\n"
         . "1. Play Balance\n"
         . "2. MoMo";
    return respStay($msg);
}

function handle_WD_PICK_DESTINATION(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '1') {
        return respNext('WD_PLAY_ENTER_AMOUNT');
    }

    if ($choice === '2') {
        return respNext('WD_MOMO_ENTER_AMOUNT');
    }

    return respStay(
        "Invalid choice.\n"
        . "1. Play Balance\n"
        . "2. MoMo"
    );
}
