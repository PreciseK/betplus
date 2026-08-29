<?php
/**
 * RegConfirmNameState — show ANM-returned name, confirm identity.
 *
 *   Your MoMo Name:
 *   KOFI MENSAH
 *   --
 *   1. Yes, continue
 *   2. No, cancel
 *
 * Input 1 → route to REG_SET_PASSWORD (proceed to password collection)
 * Input 2 → END with cancellation message
 * Other  → re-render with error
 *
 * Reads:  session.data.regName  (set by REG_PICK_PROVIDER)
 * Writes: nothing (just routes)
 */

function render_REG_CONFIRM_NAME(array &$session): array
{
    $name = $session['data']['regName'] ?? '(name unavailable)';
    $msg = "Your MoMo Name:\n"
         . $name . "\n"
         . "--\n"
         . "1. Yes, continue\n"
         . "2. No, cancel";
    return respStay($msg);
}

function handle_REG_CONFIRM_NAME(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '1') {
        return respNext('REG_SET_PASSWORD');
    }
    if ($choice === '2') {
        return respEnd("Registration cancelled.\nThank you.");
    }

    return respStay(
        "Invalid choice.\n"
        . "1. Yes, continue\n"
        . "2. No, cancel"
    );
}
