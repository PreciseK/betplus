<?php
/**
 * WdPlayEnterAmountState
 *
 *   Move from Payout to Play
 *   --
 *   Payout Bal: GHS 50.00
 *   Min: GHS 1.00
 *   Max: GHS 5000.00
 *   --
 *   Type amount in GHS:
 *
 * Free-form amount entry, same parsing rules as deposit:
 *   "20", "20.50", ".5" all accepted.
 *   Validates against MIN/MAX and against the current Payout balance.
 *
 * On valid amount → stash in session, route to WD_PLAY_CONFIRM.
 *
 * Why we re-check balance here (not just in the final transfer):
 *   Cheaper to bail before showing a confirm screen than after.
 *   The transfer logic re-checks inside its txn lock anyway — the read
 *   here is the user-friendly version, the locked re-check is the
 *   correctness version.
 */

function render_WD_PLAY_ENTER_AMOUNT(array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    try {
        $bal = playerBalances($session['playerId']);
        $payoutLine = "Payout Bal: " . formatPesewas($bal['payout']);
    } catch (Throwable $e) {
        ussdLog('WD_PLAY_AMT_BAL_ERROR', ['playerId' => $session['playerId'], 'error' => $e->getMessage()]);
        $payoutLine = "Payout Bal: --";
    }

    $msg = "Move Payout to Play\n"
         . "--\n"
         . $payoutLine . "\n"
         . "Min: " . formatPesewas(WITHDRAWAL_MIN_PESEWAS) . "\n"
         . "Max: " . formatPesewas(WITHDRAWAL_MAX_PESEWAS) . "\n"
         . "--\n"
         . "Type amount in GHS:";
    return respStay($msg);
}

function handle_WD_PLAY_ENTER_AMOUNT(string $input, array &$session): array
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

    // Convert to pesewas — integer math, no float drift
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

    // Soft balance check — the locked re-check inside the transfer is
    // the source of truth, but bailing here is friendlier than letting
    // the user type their password only to get rejected for funds.
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
            // If balance read fails, fall through — transfer will check
        }
    }

    $session['data']['wdAmountPesewas'] = $amountPesewas;
    return respNext('WD_PLAY_CONFIRM');
}
