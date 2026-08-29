<?php
/**
 * DepEnterAmountState
 *
 *   Enter Deposit Amount
 *   --
 *   Min: GHS 2.00
 *   Max: GHS 5000.00
 *   --
 *   Type amount in GHS:
 *
 * Input = number, possibly decimal (e.g. "20", "20.5", "20.50")
 *   - Parse to pesewas (multiply by 100)
 *   - If in range, stash in session, route to DEP_CONFIRM
 *   - If out of range or unparseable, re-render with error
 */

function render_DEP_ENTER_AMOUNT(array &$session): array
{
    global $USSD_CONFIG;
    $min = formatPesewas((int)($USSD_CONFIG['deposit']['min_pesewas'] ?? 200));
    $max = formatPesewas((int)($USSD_CONFIG['deposit']['max_pesewas'] ?? 500000));

    $msg = "Enter Deposit Amount\n"
         . "--\n"
         . "Min: $min\n"
         . "Max: $max\n"
         . "--\n"
         . "Type amount in GHS:";
    return respStay($msg);
}

function handle_DEP_ENTER_AMOUNT(string $input, array &$session): array
{
    $raw = trim($input);

    // Accept "20", "20.50", ".5", but reject letters and weird whitespace
    if (!preg_match('/^\d+(\.\d{1,2})?$|^\.\d{1,2}$/', $raw)) {
        return respStay(
            "Invalid amount.\n"
            . "--\n"
            . "Enter a number\n"
            . "like 20 or 20.50:"
        );
    }

    // Convert to pesewas (integer math to avoid floating point cents-loss)
    $parts = explode('.', $raw);
    $cedis = (int)($parts[0] === '' ? '0' : $parts[0]);
    $pesewasFrac = 0;
    if (isset($parts[1])) {
        $fracStr = str_pad($parts[1], 2, '0', STR_PAD_RIGHT);  // "5" → "50"
        $pesewasFrac = (int)$fracStr;
    }
    $amountPesewas = $cedis * 100 + $pesewasFrac;

    global $USSD_CONFIG;
    $min = (int)($USSD_CONFIG['deposit']['min_pesewas'] ?? 200);
    $max = (int)($USSD_CONFIG['deposit']['max_pesewas'] ?? 500000);

    if ($amountPesewas < $min) {
        return respStay(
            "Too low. Min " . formatPesewas($min) . "\n"
            . "--\n"
            . "Type amount in GHS:"
        );
    }
    if ($amountPesewas > $max) {
        return respStay(
            "Too high. Max " . formatPesewas($max) . "\n"
            . "--\n"
            . "Type amount in GHS:"
        );
    }

    $session['data']['depAmountPesewas'] = $amountPesewas;
    return respNext('DEP_CONFIRM');
}
