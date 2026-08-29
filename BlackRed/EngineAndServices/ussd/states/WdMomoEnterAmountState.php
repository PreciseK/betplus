<?php
/**
 * WdMomoEnterAmountState
 *
 *   Withdraw to MoMo
 *   --
 *   Payout Bal: GHS 50.00
 *   Min: GHS 1.00
 *   Max: GHS 5000.00
 *   --
 *   Type amount in GHS:
 *
 * Same parsing rules as deposit and Payout→Play. The 0.5% fee is absorbed
 * by BlackRed — player receives the full amount in their MoMo.
 *
 * Cooldown check happens at preflight time, not here. Doing it here would
 * surface "you're blocked" before the user knows what they tried to do.
 */

function render_WD_MOMO_ENTER_AMOUNT(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $bal = playerBalances($session['playerId']);
        $payoutLine = "Payout Bal: " . formatPesewas($bal['payout']);
    } catch (Throwable $e) {
        ussdLog('WD_MOMO_AMT_BAL_ERROR', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        $payoutLine = "Payout Bal: --";
    }

    $msg = "Withdraw to MoMo\n"
         . "--\n"
         . $payoutLine . "\n"
         . "Min: " . formatPesewas(WITHDRAWAL_MIN_PESEWAS) . "\n"
         . "Max: " . formatPesewas(WITHDRAWAL_MAX_PESEWAS) . "\n"
         . "--\n"
         . "Type amount in GHS:";
    return respStay($msg);
}

function handle_WD_MOMO_ENTER_AMOUNT(string $input, array &$session): array
{
    $raw = trim($input);

    if (!preg_match('/^\d+(\.\d{1,2})?$|^\.\d{1,2}$/', $raw)) {
        return respStay(
            "Invalid amount.\n"
            . "--\n"
            . "Enter a number\n"
            . "like 20 or 20.50:"
        );
    }

    // Integer math — no float drift
    $parts = explode('.', $raw);
    $cedis = (int)($parts[0] === '' ? '0' : $parts[0]);
    $pesewasFrac = 0;
    if (isset($parts[1])) {
        $fracStr = str_pad($parts[1], 2, '0', STR_PAD_RIGHT);
        $pesewasFrac = (int)$fracStr;
    }
    $amountPesewas = $cedis * 100 + $pesewasFrac;

    if ($amountPesewas < WITHDRAWAL_MIN_PESEWAS) {
        return respStay(
            "Too low. Min " . formatPesewas(WITHDRAWAL_MIN_PESEWAS) . "\n"
            . "--\n"
            . "Type amount in GHS:"
        );
    }
    if ($amountPesewas > WITHDRAWAL_MAX_PESEWAS) {
        return respStay(
            "Too high. Max " . formatPesewas(WITHDRAWAL_MAX_PESEWAS) . "\n"
            . "--\n"
            . "Type amount in GHS:"
        );
    }

    // Soft balance check — better UX than a hard reject after password
    if ($session['playerId'] !== null) {
        try {
            $bal = playerBalances($session['playerId']);
            if ($amountPesewas > $bal['payout']) {
                return respStay(
                    "Not enough in Payout.\n"
                    . "You have " . formatPesewas($bal['payout']) . "\n"
                    . "--\n"
                    . "Type amount in GHS:"
                );
            }
        } catch (Throwable) {
            // Soft check failure falls through — preflight will catch it
        }
    }

    $session['data']['wdAmountPesewas'] = $amountPesewas;
    return respNext('WD_MOMO_CONFIRM');
}
