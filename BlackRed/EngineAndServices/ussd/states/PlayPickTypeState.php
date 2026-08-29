<?php
/**
 * PlayPickTypeState
 *
 *   Choose Your Game:
 *   --
 *   1. 1 Card (x2)
 *   2. 2 Cards (x10)
 *   3. 3 Cards (x20)
 *   4. 4 Cards (x50)
 *   5. 5 Cards (x100)
 *
 * Compact copy ("3 Cards(x20)") per the house style. Input 1-5 sets the
 * game type and routes to color entry.
 */

function render_PLAY_PICK_TYPE(array &$session): array
{
    $msg = "Choose Your Game:\n"
         . "--\n"
         . "1. 1 Card(x2)\n"
         . "2. 2 Cards(x10)\n"
         . "3. 3 Cards(x20)\n"
         . "4. 4 Cards(x50)\n"
         . "5. 5 Cards(x100)";
    return respStay($msg);
}

function handle_PLAY_PICK_TYPE(string $input, array &$session): array
{
    $choice = trim($input);
    if (!in_array($choice, ['1','2','3','4','5'], true)) {
        return respStay(
            "Invalid choice. Pick 1-5:\n"
            . "1. 1 Card(x2)\n"
            . "2. 2 Cards(x10)\n"
            . "3. 3 Cards(x20)\n"
            . "4. 4 Cards(x50)\n"
            . "5. 5 Cards(x100)"
        );
    }

    $session['data']['playGameType'] = (int)$choice;
    return respNext('PLAY_PICK_COLORS');
}
