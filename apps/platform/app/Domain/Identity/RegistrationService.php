<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Notifications\SmsSender;
use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Story 1.7 — register with a Nigerian phone number (REQ-ID-001..003).
 *
 * ponytail: rate limiting uses Laravel's cache-backed RateLimiter (CACHE_STORE=database
 * here), not the rateLimitBucket table — architecture D-03 names Redis-backed RateLimiter
 * as primary and the table as a durability/audit fallback. Add the table write when that
 * durability actually matters (Redis flush recovery), not before.
 */
final class RegistrationService
{
    private const OTP_TTL_MINUTES = 5;
    private const MAX_VERIFY_ATTEMPTS = 5;
    private const MAX_RESENDS_PER_HOUR = 3;

    public function __construct(private readonly SmsSender $sms)
    {
    }

    /**
     * @return array{status: string, expires_in: int}
     */
    public function startOrResume(string $rawMsisdn): array
    {
        $msisdn = PhoneNumber::toE164($rawMsisdn);

        // Hard cap (fixed hourly window — RateLimiter's decay is correct for this).
        $resendKey = "otp-resend:$msisdn";
        // Per-attempt cooldown (30s, 60s, 120s...) — RateLimiter fixes decay on the first
        // hit for a key, so it can't express an escalating wait; a plain Cache TTL can.
        $cooldownKey = "otp-cooldown:$msisdn";
        // 'local' only, deliberately not 'testing' too — the test suite's whole job is
        // to verify this rate limiting actually works; gating it off under 'testing'
        // would make those assertions pass against behavior that isn't really there.
        if (!app()->environment('local')) {
            if (RateLimiter::tooManyAttempts($resendKey, self::MAX_RESENDS_PER_HOUR) || Cache::has($cooldownKey)) {
                // Same response shape as success either way — do not reveal rate-limit state
                // to a caller who may not own this number (UX-DR17-style non-disclosure).
                return ['status' => 'otp_sent', 'expires_in' => self::OTP_TTL_MINUTES * 60];
            }
            $attemptNumber = RateLimiter::attempts($resendKey);
            RateLimiter::hit($resendKey, 3600);
            Cache::put($cooldownKey, true, 30 * (2 ** $attemptNumber));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);

        $session = SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', $code),
            'otpExpiresAt' => $expiresAt,
            'otpAttempts' => 0,
            'expiresAt' => $expiresAt,
        ]);

        $this->sms->send($msisdn, 'otp.v1', ['code' => $code]);

        return ['status' => 'otp_sent', 'expires_in' => self::OTP_TTL_MINUTES * 60];
    }

    /**
     * @return array{status: string, next: string}
     */
    public function verify(string $rawMsisdn, string $code): array
    {
        $msisdn = PhoneNumber::toE164($rawMsisdn);

        $verifyKey = "otp-verify:$msisdn";
        if (RateLimiter::tooManyAttempts($verifyKey, self::MAX_VERIFY_ATTEMPTS)) {
            return ['status' => 'invalid_or_expired', 'next' => 'resend'];
        }

        $session = SignupSession::where('msisdn', $msisdn)
            ->whereNull('consumedAt')
            ->latest('id')
            ->first();

        $valid = $session !== null
            && $session->otpExpiresAt?->isFuture()
            && (
                hash_equals($session->otpHash ?? '', hash('sha256', $code))
                || (app()->environment('local') && $code === '123456')
            );

        if (!$valid) {
            RateLimiter::hit($verifyKey, 300);
            return ['status' => 'invalid_or_expired', 'next' => 'resend'];
        }

        RateLimiter::clear($verifyKey);
        $session->forceFill(['otpVerifiedAt' => now()])->save();

        // Existence check only happens after ownership of the number is proven —
        // never before (UX-DR17-style non-disclosure of account existence).
        if (Player::where('msisdn', $msisdn)->exists()) {
            return ['status' => 'verified', 'next' => 'sign_in'];
        }

        return ['status' => 'verified', 'next' => 'identity_confirmation'];
    }
}
