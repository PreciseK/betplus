<?php

declare(strict_types=1);

namespace App\Domain\BackOffice;

use App\Models\InstitutionUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Story 6.1 (REQ-BO-001/011/014) — a completely separate auth mechanism from
 * SessionService (players): its own cache-key namespace ('institution-token:'), its
 * own TTL, and mandatory MFA with no bypass. There is no institution-user equivalent
 * of "sign in with just an OTP" — password AND a confirmed TOTP device are both
 * required, every time.
 *
 * Rate limiting mirrors RegistrationService's cache-backed RateLimiter pattern (same
 * ponytail note applies: CACHE_STORE=database here, swap for Redis in production).
 * This is the highest-value credential surface in the app (REQ-BO-014), so it gets a
 * per-account lock (targeted brute force), a coarser per-IP lock (credential stuffing
 * across accounts), and a per-challenge lock on MFA codes (a 6-digit TOTP is guessable
 * without one).
 */
final class InstitutionAuthService
{
    private const ACCESS_TOKEN_TTL_SECONDS = 28800; // 8 hours — supports operator shift length (was 15 min)
    private const MFA_CHALLENGE_TTL_SECONDS = 300;
    private const MAX_SIGN_IN_ATTEMPTS_PER_ACCOUNT = 5;
    private const MAX_SIGN_IN_ATTEMPTS_PER_IP = 20;
    private const SIGN_IN_DECAY_SECONDS = 900; // 15 minutes
    private const MAX_MFA_ATTEMPTS_PER_CHALLENGE = 5;

    // REQ-BO-011 — "IP allowlisting applies to privileged roles."
    private const PRIVILEGED_ROLES = ['super_admin', 'system_admin', 'finance', 'compliance'];

    public function __construct(
        private readonly TotpService $totp,
        private readonly MfaSecretCipher $cipher,
    ) {
    }

    /**
     * @return array{status:'mfa_setup_required', challenge_id:string, secret:string}
     *              |array{status:'mfa_required', challenge_id:string}
     *              |array{status:'invalid_credentials'|'account_suspended'|'ip_not_allowlisted'|'too_many_attempts'}
     */
    public function signIn(string $email, string $password, ?string $ipAddress): array
    {
        $accountKey = "institution-signin:$email";
        $ipKey = 'institution-signin-ip:' . ($ipAddress ?? 'unknown');
        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_SIGN_IN_ATTEMPTS_PER_ACCOUNT)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_SIGN_IN_ATTEMPTS_PER_IP)) {
            return ['status' => 'too_many_attempts'];
        }

        $user = InstitutionUser::where('email', $email)->first();
        if ($user === null || !password_verify($password, $user->passwordHash)) {
            RateLimiter::hit($accountKey, self::SIGN_IN_DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::SIGN_IN_DECAY_SECONDS);

            return ['status' => 'invalid_credentials'];
        }
        RateLimiter::clear($accountKey);

        if ($user->status !== 'active') {
            return ['status' => 'account_suspended'];
        }
        if ($this->requiresIpAllowlist($user) && !$this->ipAllowed($user, $ipAddress)) {
            return ['status' => 'ip_not_allowlisted'];
        }

        $challengeId = (string) Str::ulid();
        Cache::put("institution-mfa-challenge:$challengeId", $user->id, self::MFA_CHALLENGE_TTL_SECONDS);

        if ($user->mfaConfirmedAt === null) {
            // First sign-in after account creation — the secret was generated at
            // creation time but never confirmed; hand it back once more so enrolment
            // can complete (REQ-BO-011: MFA is mandatory, there is no skip path).
            return [
                'status' => 'mfa_setup_required',
                'challenge_id' => $challengeId,
                'secret' => $this->cipher->decrypt($user->mfaSecretEncrypted),
            ];
        }

        return ['status' => 'mfa_required', 'challenge_id' => $challengeId];
    }

    /** @return array{status:'ok', access_token:string, expires_in:int, role:string}|array{status:'invalid_code'|'challenge_expired'|'too_many_attempts'} */
    public function verifyMfa(string $challengeId, string $code): array
    {
        $attemptsKey = "institution-mfa-attempts:$challengeId";
        if (RateLimiter::tooManyAttempts($attemptsKey, self::MAX_MFA_ATTEMPTS_PER_CHALLENGE)) {
            // The challenge itself is burned, not just this attempt — a fresh signIn()
            // call (and its own rate limit) is required to get a new one.
            Cache::forget("institution-mfa-challenge:$challengeId");

            return ['status' => 'too_many_attempts'];
        }

        $userId = Cache::get("institution-mfa-challenge:$challengeId");
        if ($userId === null) {
            return ['status' => 'challenge_expired'];
        }

        $user = InstitutionUser::find($userId);
        if ($user === null) {
            return ['status' => 'challenge_expired'];
        }

        $secret = $this->cipher->decrypt($user->mfaSecretEncrypted);
        if (!$this->totp->verify($secret, $code)) {
            RateLimiter::hit($attemptsKey, self::MFA_CHALLENGE_TTL_SECONDS);

            return ['status' => 'invalid_code'];
        }

        RateLimiter::clear($attemptsKey);
        Cache::forget("institution-mfa-challenge:$challengeId");
        if ($user->mfaConfirmedAt === null) {
            $user->update(['mfaConfirmedAt' => now()]);
        }
        $user->update(['lastLoginAt' => now()]);

        $accessToken = bin2hex(random_bytes(32));
        Cache::put("institution-token:$accessToken", $user->id, self::ACCESS_TOKEN_TTL_SECONDS);

        return ['status' => 'ok', 'access_token' => $accessToken, 'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS, 'role' => $user->role];
    }

    public function resolveToken(string $accessToken): ?InstitutionUser
    {
        $userId = Cache::get("institution-token:$accessToken");

        return $userId === null ? null : InstitutionUser::find($userId);
    }

    private function requiresIpAllowlist(InstitutionUser $user): bool
    {
        return in_array($user->role, self::PRIVILEGED_ROLES, true) && $user->ipAllowlist !== null;
    }

    private function ipAllowed(InstitutionUser $user, ?string $ipAddress): bool
    {
        if ($ipAddress === null) {
            return false;
        }
        $allowed = array_map('trim', explode(',', (string) $user->ipAllowlist));

        return in_array($ipAddress, $allowed, true);
    }
}
