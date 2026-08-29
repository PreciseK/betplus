<?php

declare(strict_types=1);

namespace BlackRed\Support;

/**
 * Money formatting helpers.
 *
 * The schema stores ALL money as BIGINT pesewas. 1 GHS = 100 pesewas.
 * This avoids float-related rounding errors entirely.
 *
 * For display in API responses or UI, we convert to a fixed 2-decimal string.
 * Always pair the formatted string with the raw pesewas integer in API
 * responses — the formatted string is for humans, the integer is for math.
 */
final class Money
{
    private function __construct() {}

    /**
     * Format pesewas as "X.XX" string. No currency symbol — that's added
     * separately at the display layer if needed.
     *
     * Negative amounts get a leading "-".
     *
     * Examples:
     *   0       → "0.00"
     *   1       → "0.01"
     *   100     → "1.00"
     *   12345   → "123.45"
     *   -250    → "-2.50"
     */
    public static function pesewasToString(int $pesewas): string
    {
        $negative = $pesewas < 0;
        $abs = abs($pesewas);
        $whole = intdiv($abs, 100);
        $frac = $abs % 100;
        return ($negative ? '-' : '') . $whole . '.' . str_pad((string)$frac, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format with the GHS prefix: "GHS 12.50". Used for emails, SMS, etc.
     */
    public static function pesewasToGhs(int $pesewas): string
    {
        return 'GHS ' . self::pesewasToString($pesewas);
    }

    /**
     * Parse a user-entered amount like "12.50" or "12" into pesewas.
     * Returns null if the input doesn't look like a valid amount.
     *
     * Used for amount fields in deposit/withdrawal/stake forms.
     */
    public static function parseGhsToPesewas(string $input): ?int
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }
        // Accept "12", "12.5", "12.50". Reject anything else.
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            return null;
        }
        if (!str_contains($trimmed, '.')) {
            return ((int)$trimmed) * 100;
        }
        [$whole, $frac] = explode('.', $trimmed);
        $frac = str_pad($frac, 2, '0', STR_PAD_RIGHT);
        return ((int)$whole) * 100 + ((int)$frac);
    }
}
