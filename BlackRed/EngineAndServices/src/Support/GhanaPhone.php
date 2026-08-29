<?php

declare(strict_types=1);

namespace BlackRed\Support;

/**
 * Ghana mobile number normalisation and provider detection.
 *
 * Ghana MSISDN formats accepted:
 *   - 0244000001         (local format with leading zero)
 *   - +233244000001      (international with +)
 *   - 233244000001       (international without +)
 *   - 0024 4000 001      (with spaces — stripped before parsing)
 *
 * Canonical storage form (matches `player.msisdn` column): 233244000001
 *   - 12 digits exactly
 *   - No leading +, no spaces, no leading 0
 *   - Country code 233
 *
 * Provider detection by network-code prefix (the first digit after 233):
 *   - 24, 54, 55, 59       → MTN
 *   - 26, 56, 57           → AirtelTigo (ATL)
 *   - 20, 50               → Telecel (formerly Vodafone) (TEL)
 *
 * This list is current as of 2025. New blocks are added periodically by NCA;
 * we should review yearly. If the prefix doesn't match a known block, we
 * return null and reject the signup.
 */
final class GhanaPhone
{
    /**
     * Normalise a user-entered phone number to the canonical 233XXXXXXXXX form.
     *
     * Returns null if the input is not a valid Ghana mobile number.
     */
    public static function normalise(string $input): ?string
    {
        // Strip everything that isn't a digit or a leading +.
        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $input);
        if (!is_string($cleaned)) {
            return null;
        }

        // If there's a leading +, drop it.
        if (str_starts_with($cleaned, '+')) {
            $cleaned = substr($cleaned, 1);
        }

        // Now we should have only digits.
        if (!preg_match('/^\d+$/', $cleaned)) {
            return null;
        }

        // Three accepted shapes:
        //   '0244000001'    (10 digits, leading 0 → strip and prepend 233)
        //   '244000001'     (9 digits, no leading 0 → prepend 233)
        //   '233244000001'  (12 digits, already in canonical form)
        $len = strlen($cleaned);

        if ($len === 10 && $cleaned[0] === '0') {
            $canonical = '233' . substr($cleaned, 1);
        } elseif ($len === 9 && $cleaned[0] !== '0') {
            $canonical = '233' . $cleaned;
        } elseif ($len === 12 && str_starts_with($cleaned, '233')) {
            $canonical = $cleaned;
        } else {
            return null;
        }

        // Final sanity check: 12 digits, starts with 233, position 3 is '2' or '5'
        // (i.e. local prefix is 02X or 05X — Ghana mobile range). We DON'T enforce
        // the specific network prefix here; ANM is the source of truth for which
        // network actually owns the number. Number portability and prefix
        // reassignments make any client-side network detection unreliable.
        if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
            return null;
        }
        $firstPrefixDigit = $canonical[3];
        if ($firstPrefixDigit !== '2' && $firstPrefixDigit !== '5') {
            return null;
        }

        return $canonical;
    }

    /**
     * Best-effort guess of the MoMo provider from prefix. Returns 'MTN' | 'ATL'
     * | 'TEL' for known prefixes, null otherwise.
     *
     * Useful for display hints, NOT for authoritative routing — number
     * portability means a 024 number could actually be on AirtelTigo. ANM is
     * the authoritative source.
     */
    public static function provider(string $canonical): ?string
    {
        if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
            return null;
        }
        return self::providerFromPrefix(substr($canonical, 3, 2));
    }

    private static function providerFromPrefix(string $prefix): ?string
    {
        return match ($prefix) {
            '24', '54', '55', '59' => 'MTN',
            '26', '27', '56', '57' => 'ATL',
            '20', '50'             => 'TEL',
            default                => null,
        };
    }

    /**
     * Format for display: "+233 24 400 0001"
     */
    public static function formatForDisplay(string $canonical): string
    {
        if (strlen($canonical) !== 12) {
            return $canonical;
        }
        return sprintf(
            '+%s %s %s %s',
            substr($canonical, 0, 3),
            substr($canonical, 3, 2),
            substr($canonical, 5, 3),
            substr($canonical, 8, 4),
        );
    }
}
