<?php

declare(strict_types=1);

namespace BlackRed\Validation;

use BlackRed\Http\Middleware\HttpException;

/**
 * Tiny input validation library.
 *
 * Used by controllers to pull typed values out of a JSON request body, with
 * clear error messages when something's wrong. Each method validates one
 * field and returns the typed value, or throws HttpException with a
 * structured 400 error.
 *
 * Why hand-rolled instead of a library:
 *   - We need exactly four primitive validators (string, int, email, phone).
 *     Pulling in a 50-class library would be massive overkill.
 *   - The error messages need to match our error response shape exactly.
 *   - Every line is auditable.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data The decoded request body
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Required string. Trims whitespace. Rejects empty after trim.
     */
    public function requireString(string $field, int $minLen = 1, int $maxLen = 255): string
    {
        if (!array_key_exists($field, $this->data)) {
            $this->fail($field, 'is required');
        }
        $value = $this->data[$field];
        if (!is_string($value)) {
            $this->fail($field, 'must be a string');
        }
        $value = trim($value);
        if (strlen($value) < $minLen) {
            $this->fail($field, "must be at least {$minLen} characters");
        }
        if (strlen($value) > $maxLen) {
            $this->fail($field, "must be at most {$maxLen} characters");
        }
        return $value;
    }

    /**
     * Optional string. Returns null if the field is missing or empty.
     */
    public function optionalString(string $field, int $maxLen = 255): ?string
    {
        if (!array_key_exists($field, $this->data)) {
            return null;
        }
        $value = $this->data[$field];
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->fail($field, 'must be a string');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLen) {
            $this->fail($field, "must be at most {$maxLen} characters");
        }
        return $value;
    }

    /**
     * Email validation using PHP's built-in filter.
     */
    public function optionalEmail(string $field): ?string
    {
        $value = $this->optionalString($field, 150);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, 'is not a valid email address');
        }
        return $value;
    }

    /**
     * Required Ghana mobile phone. Returns the canonical 233XXXXXXXXX form.
     */
    public function requirePhone(string $field): string
    {
        $raw = $this->requireString($field, 9, 20);
        $normalised = \BlackRed\Support\GhanaPhone::normalise($raw);
        if ($normalised === null) {
            $this->fail($field, 'is not a valid Ghana mobile number');
        }
        return $normalised;
    }

    /**
     * Required value that must be one of a fixed set of allowed strings.
     * Comparison is case-sensitive — pass values pre-normalised if needed.
     *
     * @param list<string> $allowed
     */
    public function requireOneOf(string $field, array $allowed): string
    {
        $value = $this->requireString($field, 1, 50);
        if (!in_array($value, $allowed, true)) {
            $this->fail($field, 'must be one of: ' . implode(', ', $allowed));
        }
        return $value;
    }

    /**
     * Required password with policy that matches the signup UI exactly:
     *   - 8 to 100 characters
     *   - At least one uppercase letter
     *   - At least one digit
     *   - At least one symbol (anything that is not letter or digit)
     *
     * The 100-char upper bound is a defence against accidental DoS via
     * extremely long passwords being slow to hash.
     */
    public function requirePassword(string $field): string
    {
        $value = $this->requireString($field, 8, 100);
        if (!preg_match('/[A-Z]/', $value)) {
            $this->fail($field, 'must contain at least one uppercase letter');
        }
        if (!preg_match('/\d/', $value)) {
            $this->fail($field, 'must contain at least one number');
        }
        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $this->fail($field, 'must contain at least one symbol');
        }
        return $value;
    }

    /**
     * Required digit-only string of an exact length (e.g. for numeric OTPs).
     */
    public function requireDigits(string $field, int $exactLen): string
    {
        $value = $this->requireString($field, $exactLen, $exactLen);
        if (!preg_match('/^\d+$/', $value)) {
            $this->fail($field, "must be {$exactLen} digits");
        }
        return $value;
    }

    /**
     * Required 4-digit numeric PIN.
     *
     * Rules:
     *   - Exactly 4 digits, no other characters
     *
     * Note: we deliberately accept any 4-digit sequence (including 0000, 1234,
     * etc.). The brute-force defence rests entirely on the per-account lockout
     * (3 strikes → 15 min lock via player.failedLoginCount + lockedUntil),
     * which makes the 10,000-combination space infeasible to exhaust online.
     */
    public function requirePin(string $field): string
    {
        $value = $this->requireString($field, 4, 4);
        if (!preg_match('/^\d{4}$/', $value)) {
            $this->fail($field, 'must be exactly 4 digits');
        }
        return $value;
    }

    /**
     * Required alphanumeric OTP code of an exact length. Accepts digits and
     * letters, normalises to uppercase. Used for the 4-character codes
     * issued by the *920*995# USSD endpoint.
     */
    public function requireOtpCode(string $field, int $exactLen): string
    {
        $value = $this->requireString($field, $exactLen, $exactLen);
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z0-9]+$/', $value)) {
            $this->fail($field, "must be {$exactLen} letters or digits");
        }
        return $value;
    }

    /**
     * @return never
     */
    private function fail(string $field, string $message): void
    {
        throw HttpException::badRequest(
            'validation_failed',
            "Field '{$field}' {$message}",
            ['field' => $field],
        );
    }
}
