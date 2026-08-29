<?php
/**
 * State registry and dispatcher.
 *
 * Each state lives in states/<Name>State.php and defines two functions:
 *   render_<NAME>(array &$session): array
 *   handle_<NAME>(string $input, array &$session): array
 *
 * Both take $session by reference so states can mutate (e.g. EntryState
 * attaches a playerId after looking up by MSISDN). The dispatcher in
 * index.php commits the post-mutation state to DB at end of turn.
 *
 * The NAME used in the state column matches the function suffix (so state
 * MAIN_MENU maps to render_MAIN_MENU / handle_MAIN_MENU in
 * states/MainMenuState.php).
 *
 * The map below is the SOURCE OF TRUTH for which states exist.
 */

const USSD_STATE_FILES = [
    'ENTRY'                => 'EntryState.php',
    'UNREGISTERED_MENU'    => 'UnregisteredMenuState.php',
    'MAIN_MENU'            => 'MainMenuState.php',
    'BALANCE'              => 'BalanceState.php',
    'LAST_STAKE'           => 'LastStakeState.php',
    'REG_PICK_PROVIDER'    => 'RegPickProviderState.php',
    'REG_CONFIRM_NAME'     => 'RegConfirmNameState.php',
    'REG_SET_PASSWORD'     => 'RegSetPasswordState.php',
    'REG_CONFIRM_PASSWORD' => 'RegConfirmPasswordState.php',
    'DEP_ENTER_AMOUNT'     => 'DepEnterAmountState.php',
    'DEP_CONFIRM'          => 'DepConfirmState.php',
    'WD_PICK_DESTINATION'  => 'WdPickDestinationState.php',
    'WD_PLAY_ENTER_AMOUNT' => 'WdPlayEnterAmountState.php',
    'WD_PLAY_CONFIRM'      => 'WdPlayConfirmState.php',
    'WD_PLAY_PASSWORD'     => 'WdPlayPasswordState.php',
    'WD_MOMO_ENTER_AMOUNT' => 'WdMomoEnterAmountState.php',
    'WD_MOMO_CONFIRM'      => 'WdMomoConfirmState.php',
    'WD_MOMO_PASSWORD'     => 'WdMomoPasswordState.php',
    'PLAY_PICK_TYPE'       => 'PlayPickTypeState.php',
    'PLAY_PICK_COLORS'     => 'PlayPickColorsState.php',
    'PLAY_ENTER_STAKE'     => 'PlayEnterStakeState.php',
    'PLAY_CONFIRM'         => 'PlayConfirmState.php',
    'PLAY_AGAIN'           => 'PlayAgainState.php',
];

/**
 * Every new dial lands here. EntryState routes from there to UNREGISTERED_MENU
 * or MAIN_MENU depending on whether the MSISDN matches a registered player.
 */
const USSD_INITIAL_STATE = 'ENTRY';

/**
 * Lazy-load the file that defines $stateName's functions.
 */
function loadStateFile(string $stateName): void
{
    static $loaded = [];
    if (isset($loaded[$stateName])) {
        return;
    }
    if (!isset(USSD_STATE_FILES[$stateName])) {
        throw new RuntimeException("Unknown USSD state: $stateName");
    }
    $path = __DIR__ . '/../states/' . USSD_STATE_FILES[$stateName];
    if (!is_file($path)) {
        throw new RuntimeException("State file not found: $path");
    }
    require_once $path;
    $loaded[$stateName] = true;
}

/**
 * Render the current state's screen. Session passed by reference so the
 * render function can mutate (used by ENTRY to attach a looked-up player).
 */
function renderState(string $stateName, array &$session): array
{
    loadStateFile($stateName);
    $fn = 'render_' . $stateName;
    if (!function_exists($fn)) {
        throw new RuntimeException("State $stateName has no render() function");
    }
    return $fn($session);
}

/**
 * Handle input on the current state. May mutate $session.
 */
function handleState(string $stateName, string $input, array &$session): array
{
    loadStateFile($stateName);
    $fn = 'handle_' . $stateName;
    if (!function_exists($fn)) {
        throw new RuntimeException("State $stateName has no handle() function");
    }
    return $fn($input, $session);
}
