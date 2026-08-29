<?php
/**
 * PlayAgainState
 *
 * Renders the result screen produced by PLAY_CONFIRM (stashed in
 * session.data.playResultMsg), which already ends with:
 *   1. Play Again
 *   2. Exit
 *
 * Handle:
 *   1 → clear result, route back to PLAY_PICK_TYPE for a fresh round
 *   2 → END "Thanks for playing"
 *   other → re-render the same result screen
 *
 * Why a separate state: the round already executed in PLAY_CONFIRM's
 * handler (money moved, audit written). PLAY_AGAIN is purely the
 * post-round menu. Keeping it separate means a Play Again loop never
 * re-runs a round by accident — each round runs exactly once, in
 * PLAY_CONFIRM, and only when the user explicitly confirms a new stake.
 */

function render_PLAY_AGAIN(array &$session): array
{
    $msg = (string)($session['data']['playResultMsg'] ?? '');
    if ($msg === '') {
        // No stashed result — shouldn't happen, but recover gracefully
        return respEnd("Thanks for playing!");
    }
    return respStay($msg);
}

function handle_PLAY_AGAIN(string $input, array &$session): array
{
    $choice = trim($input);

    if ($choice === '1') {
        unset($session['data']['playResultMsg']);
        return respNext('PLAY_PICK_TYPE');
    }

    if ($choice === '2') {
        unset($session['data']['playResultMsg']);
        return respEnd("Thanks for playing!\nGood luck next time.");
    }

    // Re-render the result with the play-again prompt
    $msg = (string)($session['data']['playResultMsg'] ?? '');
    if ($msg === '') {
        return respEnd("Thanks for playing!");
    }
    return respStay($msg);
}
