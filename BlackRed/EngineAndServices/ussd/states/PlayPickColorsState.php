<?php
/**
 * PlayPickColorsState
 *
 *   Enter your Selection
 *   For 3 Cards
 *   -----------
 *   B = Black
 *   R = Red
 *   Example: BBRB
 *
 * The user types a string of B/R letters whose length MUST equal the game
 * type. e.g. for a 3-card game, "BRB" or "rrr" etc.
 *
 * Parsing:
 *   - Case-insensitive (b/r accepted)
 *   - Whitespace stripped ("B R B" → "BRB")
 *   - Each char must be B or R
 *   - Length must equal game type
 *
 * On valid → store colorPicks as ['black','red','black'], route to stake.
 */

function render_PLAY_PICK_COLORS(array &$session): array
{
    $gameType = (int)($session['data']['playGameType'] ?? 0);
    if ($gameType < 1 || $gameType > 5) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $cardWord = $gameType === 1 ? "1 Card" : "$gameType Cards";
    $example  = str_repeat('B', $gameType);  // e.g. "BBB"

    $msg = "Enter your Selection\n"
         . "For $cardWord\n"
         . "-----------\n"
         . "B = Black\n"
         . "R = Red\n"
         . "Example: $example";
    return respStay($msg);
}

function handle_PLAY_PICK_COLORS(string $input, array &$session): array
{
    $gameType = (int)($session['data']['playGameType'] ?? 0);
    if ($gameType < 1 || $gameType > 5) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    // Normalise: strip whitespace, uppercase
    $raw = strtoupper(preg_replace('/\s+/', '', $input));

    // Length must match game type
    if (strlen($raw) !== $gameType) {
        $cardWord = $gameType === 1 ? "1 letter" : "$gameType letters";
        return respStay(
            "Enter exactly $cardWord.\n"
            . "B = Black, R = Red\n"
            . "Example: " . str_repeat('R', $gameType) . "\n"
            . "Try again:"
        );
    }

    // Each char must be B or R
    $colorPicks = [];
    for ($i = 0; $i < strlen($raw); $i++) {
        $ch = $raw[$i];
        if ($ch === 'B') {
            $colorPicks[] = 'black';
        } elseif ($ch === 'R') {
            $colorPicks[] = 'red';
        } else {
            return respStay(
                "Only B or R allowed.\n"
                . "B = Black, R = Red\n"
                . "Example: " . str_repeat('B', $gameType) . "\n"
                . "Try again:"
            );
        }
    }

    $session['data']['playColorPicks'] = $colorPicks;
    return respNext('PLAY_ENTER_STAKE');
}
