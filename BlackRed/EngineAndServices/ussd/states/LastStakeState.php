<?php
/**
 * LastStakeState
 *
 *   Your Last Stake Is A Win
 *   --
 *   You Staked GHS 10.00 For 3 Cards(x20)
 *   --
 *   Your Selection: BRB
 *   The Result: RRB
 *
 * Or, if they've never played:
 *
 *   You have not played yet.
 *
 * Worst-case length (5-card x100 stake of GHS 2,000):
 *   "Your Last Stake Is A Loss\n--\nYou Staked GHS 2,000.00 For 5 Cards(x100)\n
 *    --\nYour Selection: BBRBR\nThe Result: RRBBR"
 *   = 26+1+3+1+45+1+3+1+22+1+18 = 122 chars — fits the 146-char USSD limit.
 *
 * Terminal state — ENDs immediately.
 *
 * gameType is rendered as "N Cards(xM)" using the spec's table:
 *   1 → "1 Card(x2)"
 *   2 → "2 Cards(x10)"
 *   3 → "3 Cards(x20)"
 *   4 → "4 Cards(x50)"
 *   5 → "5 Cards(x100)"
 *
 * drawnCards is JSON like ["7H","KS","2C"]. We reduce it to just the colour
 * letters (B/R) so the display matches the user's selection format.
 */

function render_LAST_STAKE(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $stake = playerLastStake($session['playerId']);
    } catch (Throwable $e) {
        ussdLog('LASTSTAKE_ERROR', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        return respEnd("Last stake temporarily unavailable.\nPlease try again.");
    }

    if ($stake === null) {
        return respEnd("You have not played yet.\nDial *920*9# anytime to play.");
    }

    $outcome = $stake['outcome'] === 'win' ? 'Win' : 'Loss';
    $gameTypeLabel = formatGameType($stake['gameType'], $stake['multiplier']);
    $stakeAmount = formatPesewas($stake['stakePesewas']);
    $selection = colorPicksToLetters($stake['colorPicks']);
    $drawnLetters = drawnCardsToLetters($stake['drawnCards']);

    $msg = "Your Last Stake Is A $outcome\n"
         . "--\n"
         . "You Staked $stakeAmount For $gameTypeLabel\n"
         . "--\n"
         . "Your Selection: $selection\n"
         . "The Result: $drawnLetters";

    return respEnd($msg);
}

function handle_LAST_STAKE(string $input, array &$session): array
{
    return respEnd("Goodbye.");
}

/**
 * Format game type for display. Compact form: "3 Cards(x20)" — no spaces
 * around hyphen, parens for multiplier. Mirrors the play menu's labels.
 */
function formatGameType(int $cards, int $multiplier): string
{
    $cardWord = $cards === 1 ? 'Card' : 'Cards';
    return "$cards $cardWord(x$multiplier)";
}

/**
 * The web app's GameEngineService stores colorPicks as a comma-separated
 * list of full words: "red,black,red". For USSD display we compress to
 * the letter form "RBR" so it (a) fits the 146-char limit and (b) matches
 * the format the user typed at stake time.
 *
 * Inputs we tolerate (whitespace, casing, separators):
 *   "red,black,red"   → "RBR"
 *   "Red, Black, Red" → "RBR"
 *   " R , B , R "     → "RBR"   (already-shortened form, just in case)
 *   "RBR"             → "RBR"   (idempotent)
 *
 * Anything we can't classify becomes "?". Empty input → "(unavailable)".
 */
function colorPicksToLetters(?string $picks): string
{
    if ($picks === null || trim($picks) === '') return '(unavailable)';

    // Split on comma OR treat as a packed letter string if no commas
    $parts = str_contains($picks, ',') ? explode(',', $picks) : str_split($picks);

    $letters = '';
    foreach ($parts as $part) {
        $p = strtoupper(trim($part));
        if ($p === '') continue;
        $first = $p[0];
        $letters .= ($first === 'R' || $first === 'B') ? $first : '?';
    }
    return $letters === '' ? '(unavailable)' : $letters;
}

/**
 * drawnCards is a JSON-encoded array like ["7H","KS","2C","2D"]. Each card's
 * suit letter is the second character — H/D are red, C/S are black. Reduce
 * the array to a "BRRB"-style string showing colour outcomes only.
 *
 * Returns "(unavailable)" if the JSON is malformed or empty, so the screen
 * always renders.
 */
function drawnCardsToLetters(?string $drawnJson): string
{
    if ($drawnJson === null || $drawnJson === '') return '(unavailable)';

    $cards = json_decode($drawnJson, true);
    if (!is_array($cards) || empty($cards)) return '(unavailable)';

    $letters = '';
    foreach ($cards as $card) {
        if (!is_string($card) || strlen($card) < 2) continue;
        $suit = strtoupper($card[strlen($card) - 1]);
        $letters .= ($suit === 'H' || $suit === 'D') ? 'R' : 'B';
    }
    return $letters === '' ? '(unavailable)' : $letters;
}
