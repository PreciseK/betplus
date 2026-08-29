<?php
/**
 * MSISDN normalization.
 *
 * Nalo sends MSISDN as 233xxxxxxxxx (no leading +). The web app stores phones
 * in the same CANONICAL form: 233XXXXXXXXX in player.msisdn. To keep the two
 * channels' data interoperable (USSD-registered user can log in via web,
 * web-registered user can deposit via USSD), we use the same canonical form.
 *
 * Inputs we accept:
 *   233244000001  → 233244000001  (already canonical)
 *   +233244000001 → 233244000001  (strip leading +)
 *   0244000001    → 233244000001  (Ghana local: strip 0, prepend 233)
 *   244000001     → 233244000001  (Ghana 9-digit: prepend 233)
 *
 * Display form (what the user sees in screen text): we still show 0244XXXXX
 * because that's how Ghanaians read their own phone numbers.
 *
 * Rejects anything that doesn't produce a valid 12-digit 233XXX form.
 */

function normalizeMsisdn(string $raw): string
{
    // Strip whitespace, dashes, parens, dots, plus signs
    $cleaned = preg_replace('/[\s\-\(\)\.\+]/', '', $raw) ?? '';
    if (!preg_match('/^\d+$/', $cleaned)) {
        throw new InvalidArgumentException("MSISDN contains non-digits: $raw");
    }

    $len = strlen($cleaned);

    if ($len === 12 && str_starts_with($cleaned, '233')) {
        $canonical = $cleaned;
    } elseif ($len === 10 && $cleaned[0] === '0') {
        $canonical = '233' . substr($cleaned, 1);
    } elseif ($len === 9) {
        $canonical = '233' . $cleaned;
    } else {
        throw new InvalidArgumentException("MSISDN has unexpected length: $raw");
    }

    // Final sanity check — 12 digits, leading 233, local prefix is 02X or 05X
    if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
        throw new InvalidArgumentException("MSISDN normalization failed: $raw");
    }
    $localPrefix = $canonical[3] ?? '';
    if ($localPrefix !== '2' && $localPrefix !== '5') {
        throw new InvalidArgumentException("MSISDN is not a Ghana mobile: $raw");
    }

    return $canonical;
}

/**
 * Convert canonical 233XXX form to display form 0XXX for screen text.
 *
 *   233244000001 → 0244000001
 */
function displayMsisdn(string $canonical): string
{
    if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
        return $canonical; // defensive — don't mangle if input wasn't canonical
    }
    return '0' . substr($canonical, 3);
}
