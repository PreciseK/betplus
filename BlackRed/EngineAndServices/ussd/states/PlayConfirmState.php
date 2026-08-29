<?php
/**
 * PlayConfirmState
 *
 *   3 Cards (x20)
 *   Picks: R B R
 *   Stake: GHS 5.00
 *   Win: GHS 100.00
 *   --
 *   1. Play
 *   2. Cancel
 *
 * On Play (1): run gamePlay() synchronously (no ANM, all internal). END
 * with the result and a Play Again offer.
 *
 * On Cancel (2): END "Cancelled."
 *
 * Result screens:
 *   WIN:  "You WON GHS 100.00! / Drawn: R B R / New Play: GHS 45 / New Payout: GHS 100 / 1.Play Again 2.Exit"
 *   LOSS: "No win this time. / Drawn: R B B / New Play: GHS 45 / 1.Play Again 2.Exit"
 *
 * Play Again routes back to PLAY_PICK_TYPE. The result + play-again prompt
 * is delivered as a CONTINUE (not END), so the user can loop. We move to a
 * dedicated PLAY_AGAIN state to read their 1/2 choice.
 *
 * colorPicks → letters helper renders ['red','black'] as "R B".
 */

function render_PLAY_CONFIRM(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $gameType   = (int)($session['data']['playGameType'] ?? 0);
    $colorPicks = $session['data']['playColorPicks'] ?? [];
    $stake      = (int)($session['data']['playStakePesewas'] ?? 0);

    if ($gameType < 1 || $gameType > 5 || count($colorPicks) !== $gameType || $stake <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $multiplier = gameMultiplier($gameType);
    $potential  = $stake * $multiplier;
    $cardWord   = $gameType === 1 ? "1 Card" : "$gameType Cards";

    $msg = "$cardWord (x$multiplier)\n"
         . "Picks: " . playColorsToLetters($colorPicks) . "\n"
         . "Stake: " . formatPesewas($stake) . "\n"
         . "Win: " . formatPesewas($potential) . "\n"
         . "--\n"
         . "1. Play\n"
         . "2. Cancel";
    return respStay($msg);
}

function handle_PLAY_CONFIRM(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '2') {
        playClearRoundData($session);
        return respEnd("Cancelled. Thank you.");
    }

    if ($choice !== '1') {
        return respStay(
            "Invalid choice.\n"
            . "1. Play\n"
            . "2. Cancel"
        );
    }

    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $gameType   = (int)($session['data']['playGameType'] ?? 0);
    $colorPicks = $session['data']['playColorPicks'] ?? [];
    $stake      = (int)($session['data']['playStakePesewas'] ?? 0);

    if ($gameType < 1 || $gameType > 5 || count($colorPicks) !== $gameType || $stake <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    try {
        $result = gamePlay($session['playerId'], $gameType, $colorPicks, $stake, $clientIp);
    } catch (InvalidArgumentException $e) {
        playClearRoundData($session);
        return respEnd($e->getMessage());
    } catch (RuntimeException $e) {
        playClearRoundData($session);
        return respEnd($e->getMessage());
    } catch (Throwable $e) {
        ussdLog('PLAY_UNEXPECTED', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        playClearRoundData($session);
        return respEnd("Play failed. Please try again later.");
    }

    // Round done — clear the round inputs (but keep session alive for Play Again)
    playClearRoundData($session);

    $drawn = playColorsToLetters($result['drawnColors']);

    if ($result['outcome'] === 'win') {
        $msg = "You WON " . formatPesewas($result['payoutPesewas']) . "!\n"
             . "Drawn: $drawn\n"
             . "New Play: " . formatPesewas($result['playBalance']) . "\n"
             . "New Payout: " . formatPesewas($result['payoutBalance']) . "\n"
             . "--\n"
             . "1. Play Again\n"
             . "2. Exit";
    } else {
        $msg = "No win this time.\n"
             . "Drawn: $drawn\n"
             . "Your Picks: " . playColorsToLetters($result['colorPicks']) . "\n"
             . "New Play: " . formatPesewas($result['playBalance']) . "\n"
             . "--\n"
             . "1. Play Again\n"
             . "2. Exit";
    }

    // Stash the result screen so PLAY_AGAIN can render it, then transition.
    // We can't use respStay here (that would re-render PLAY_CONFIRM) nor a
    // plain respNext (that would render PLAY_AGAIN fresh, losing this result).
    // So: hand PLAY_AGAIN the text to show.
    $session['data']['playResultMsg'] = $msg;
    return respNext('PLAY_AGAIN');
}

/**
 * Render ['red','black','red'] as "R B R".
 */
function playColorsToLetters(array $colors): string
{
    return implode(' ', array_map(
        fn($c) => $c === 'red' ? 'R' : 'B',
        $colors
    ));
}

/**
 * Clear per-round inputs from the session (keeps session itself alive).
 */
function playClearRoundData(array &$session): void
{
    unset(
        $session['data']['playGameType'],
        $session['data']['playColorPicks'],
        $session['data']['playStakePesewas']
    );
}
