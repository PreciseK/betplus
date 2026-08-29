<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use InvalidArgumentException;

/**
 * Normalises Nigerian MSISDNs to E.164 (REQ-ID-001). Accepts local (0803...),
 * bare (803...), and already-E.164 (+234803... / 234803...) input.
 */
final class PhoneNumber
{
    public static function toE164(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        $national = match (true) {
            str_starts_with($digits, '234') && strlen($digits) === 13 => substr($digits, 3),
            str_starts_with($digits, '0') && strlen($digits) === 11 => substr($digits, 1),
            strlen($digits) === 10 => $digits,
            default => null,
        };

        // Nigerian mobile numbers: 10 national digits starting 7, 8, or 9.
        if ($national === null || !preg_match('/^[789]\d{9}$/', $national)) {
            throw new InvalidArgumentException('Not a valid Nigerian mobile number.');
        }

        return '+234' . $national;
    }

    /** 10 national digits, no +234 — the format OPay's wallet-validate API expects. */
    public static function toLocalDigits(string $e164): string
    {
        return substr($e164, 4);
    }
}
