<?php

declare(strict_types=1);

namespace App\Domain\BackOffice;

/**
 * RFC 6238 TOTP over RFC 4226 HOTP — SHA-1, 6 digits, 30-second step (the values every
 * authenticator app — Google Authenticator, Authy, 1Password — assumes by default).
 * Hand-rolled rather than a composer package: the algorithm is ~30 lines of stdlib
 * hash_hmac calls with published test vectors to verify against (see
 * TotpServiceTest), not something that benefits from an external dependency the way
 * TLS or password hashing would.
 */
final class TotpService
{
    private const DIGITS = 6;
    private const STEP_SECONDS = 30;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh base32 secret, suitable for an otpauth:// URI or manual entry. */
    public function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public function currentCode(string $base32Secret, ?int $timestamp = null): string
    {
        return $this->codeForCounter(self::base32Decode($base32Secret), $this->counterFor($timestamp ?? time()));
    }

    /**
     * Accepts the current step and one step on either side (REQ-BO-011 doesn't specify
     * a drift tolerance; +-30s is the conventional default every authenticator app and
     * verifier assumes).
     */
    public function verify(string $base32Secret, string $code, ?int $timestamp = null): bool
    {
        $key = self::base32Decode($base32Secret);
        $counter = $this->counterFor($timestamp ?? time());

        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals($this->codeForCounter($key, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    private function counterFor(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP_SECONDS);
    }

    private function codeForCounter(string $key, int $counter): string
    {
        $counterBytes = pack('N*', 0, $counter); // 8-byte big-endian (two 32-bit halves — $counter fits in the low half until year 2106)
        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $bits = '';
        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue; // trailing padding bits, not a full byte
            }
            $binary .= chr(bindec($byte));
        }

        return $binary;
    }
}
