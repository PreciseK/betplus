<?php
/**
 * PlayEnterStakeState
 *
 *   Enter Your Stake
 *   --
 *   Play Bal: GHS 50.00
 *   Min: GHS 2.00
 *   Max: GHS 2000.00
 *   --
 *   Type stake in GHS:
 *
 * Free-form amount entry (same parser as deposit/withdrawal). Validates
 * range + Play balance (soft check; gamePlay re-checks under lock).
 *
 * On valid → store stake, route to PLAY_CONFIRM.
 */

function render_PLAY_ENTER_STAKE(array &$session): array
{
    global $USSD_CONFIG;

    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $min = formatPesewas(GAME_MIN_STAKE_PESEWAS);
    $max = formatPesewas((int)($USSD_CONFIG['game']['max_stake_pesewas'] ?? 200000));

    try {
        $bal = playerBalances($session['playerId']);
        $balLine = "Play Bal: " . formatPesewas($bal['play']);
    } catch (Throwable $e) {
        ussdLog('PLAY_STAKE_BAL_ERROR', ['playerId' => $session['playerId'], 'error' => $e->getMessage()]);
        $balLine = "Play Bal: --";
    }

    $msg = "Enter Your Stake\n"
         . "--\n"
         . $balLine . "\n"
         . "Min: $min\n"
         . "Max: $max\n"
         . "--\n"
         . "Type stake in GHS:";
    return respStay($msg);
}

function handle_PLAY_ENTER_STAKE(string $input, array &$session): array
{
    global $USSD_CONFIG;

    $raw = trim($input);

    if (!preg_match('/^\d+(\.\d{1,2})?$|^\.\d{1,2}$/', $raw)) {
        return respStay(
            "Invalid stake.\n"
            . "--\n"
            . "Enter a number\n"
            . "like 5 or 5.50:"
        );
    }

    $parts = explode('.', $raw);
    $cedis = (int)($parts[0] === '' ? '0' : $parts[0]);
    $pesewasFrac = 0;
    if (isset($parts[1])) {
        $fracStr = str_pad($parts[1], 2, '0', STR_PAD_RIGHT);
        $pesewasFrac = (int)$fracStr;
    }
    $stakePesewas = $cedis * 100 + $pesewasFrac;

    $min = GAME_MIN_STAKE_PESEWAS;
    $max = (int)($USSD_CONFIG['game']['max_stake_pesewas'] ?? 200000);

    if ($stakePesewas < $min) {
        return respStay(
            "Too low. Min " . formatPesewas($min) . "\n"
            . "--\n"
            . "Type stake in GHS:"
        );
    }
    if ($stakePesewas > $max) {
        return respStay(
            "Too high. Max " . formatPesewas($max) . "\n"
            . "--\n"
            . "Type stake in GHS:"
        );
    }

    // Soft balance check
    if ($session['playerId'] !== null) {
        try {
            $bal = playerBalances($session['playerId']);
            if ($stakePesewas > $bal['play']) {
                return respStay(
                    "Not enough in Play.\n"
                    . "You have " . formatPesewas($bal['play']) . "\n"
                    . "--\n"
                    . "Type stake in GHS:"
                );
            }
        } catch (Throwable) {
            // Fall through — gamePlay will check under lock
        }
    }

    $session['data']['playStakePesewas'] = $stakePesewas;
    return respNext('PLAY_CONFIRM');
}
