<?php
/**
 * EntryState
 *
 * First state every dial lands on. Looks up the player by MSISDN:
 *   - registered + active → attach playerId to session, transition to MAIN_MENU
 *   - registered + frozen → END with explanation
 *   - registered + closed → END with explanation
 *   - not registered → transition to UNREGISTERED_MENU
 *
 * This state has only a render() — there's nothing to "handle" because it
 * doesn't ask the user anything. It either ends or transitions immediately
 * to a state that does ask. So handle() exists but is a defensive no-op.
 */

function render_ENTRY(array &$session): array
{
    $player = playerByMsisdn($session['msisdn']);

    if ($player === null) {
        // Not registered — go to unregistered menu
        return respNext('UNREGISTERED_MENU');
    }

    if ($player['accountStatus'] === 'frozen') {
        return respEnd("Your account is frozen.\nPlease contact support.");
    }

    if ($player['accountStatus'] === 'closed') {
        return respEnd("Your account is closed.\nPlease contact support.");
    }

    // Lockout check — applied across web + USSD via the shared
    // player.failedLoginCount + lockedUntil columns. End the session
    // immediately rather than letting them dig into the menu and waste
    // their turn before being told they're locked.
    $minsLeft = playerLockoutCheck((int)$player['id']);
    if ($minsLeft !== null) {
        ussdLog('ENTRY_LOCKED', [
            'playerId' => $player['id'],
            'minsLeft' => $minsLeft,
        ]);
        return respEnd(
            "Too many wrong PIN attempts.\n"
            . "Try again in $minsLeft minute" . ($minsLeft === 1 ? '' : 's') . "."
        );
    }

    // Registered and active. Attach player to session and proceed to menu.
    $session['playerId'] = $player['id'];
    $session['data']['firstName'] = playerFirstName($player);

    return respNext('MAIN_MENU');
}

function handle_ENTRY(string $input, array &$session): array
{
    // Defensive: handle should never be reached because render always transitions
    // or ends. If we land here, something's odd — end gracefully.
    return respEnd("Something went wrong. Please dial *920*9# again.");
}
