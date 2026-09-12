<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\SignupSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Story 1.11 — sign in and hold a session (REQ-ID-026). Reuses RegistrationService's OTP
 * flow entirely (send/verify) — this only covers what happens after verify() returns
 * next: "sign_in": issuing tokens, and their rotation/reuse detection on refresh.
 *
 * ponytail: no Redis in this environment. The access token's live/revocable state is
 * kept in Laravel's Cache facade (database store here) rather than the playerSession row
 * directly, matching the architecture's own "Redis is primary; this table is the audit
 * mirror" framing — swap Cache's store for Redis in production, nothing else changes.
 */
final class SessionService
{
    private const ACCESS_TOKEN_TTL_SECONDS = 1800; // 30 minutes (REQ-ID-026)
    private const REFRESH_TOKEN_TTL_DAYS = 30;
    // This endpoint can't be credential-brute-forced (it requires an already
    // OTP-verified, single-use SignupSession — see RegistrationService's own
    // verify-attempt limit), so this is IP-level DoS/enumeration hardening only.
    private const MAX_ATTEMPTS_PER_IP = 30;
    private const DECAY_SECONDS = 300;

    public function __construct(private readonly AnalyticsEventRecorder $analytics)
    {
    }

    /** @return array{status: string, registered_name?: string, access_token?: string, refresh_token?: string, expires_in?: int} */
    public function signIn(string $rawMsisdn, string $channel = 'web', ?string $ipAddress = null): array
    {
        $ipKey = 'sign-in-ip:' . ($ipAddress ?? 'unknown');
        // 'local' only — see RegistrationService's identical note on why 'testing' must
        // stay out of this gate.
        if (!app()->environment('local')) {
            if (RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
                return ['status' => 'too_many_attempts'];
            }
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
        }

        $msisdn = PhoneNumber::toE164($rawMsisdn);

        $session = SignupSession::verifiedAndUnconsumed($msisdn)->first();
        if ($session === null) {
            return ['status' => 'otp_not_verified'];
        }

        $player = Player::where('msisdn', $msisdn)->first();
        if ($player === null) {
            return ['status' => 'no_account'];
        }

        if ($player->accountStatus === 'inactive' || $player->accountStatus === 'closed') {
            return [
                'status' => 'account_inactive',
                'message' => 'Your account is deactivated. You may request reactivation through Support within 6 months of deactivation.',
                'deactivated_at' => $player->deletedAt?->toIso8601String(),
            ];
        }

        $session->forceFill(['consumedAt' => now()])->save();

        $tokens = $this->issueTokensFor($player, $msisdn, $channel);

        $this->analytics->record('sign_in_completed', $player, $channel);

        return array_merge(
            ['status' => 'signed_in', 'registered_name' => $player->registeredName],
            $tokens,
        );
    }

    /**
     * Public because IdentityConfirmationService::completeRegistration() calls it too —
     * registration ends with the player already signed in, not a second OTP round-trip.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokensFor(Player $player, string $msisdn, string $channel): array
    {
        return $this->issueTokens($player, $msisdn, $channel, family: null);
    }

    /** @return array{status: string, access_token?: string, refresh_token?: string, expires_in?: int} */
    public function refresh(string $rawRefreshToken): array
    {
        $hash = hash('sha256', $rawRefreshToken);
        $current = PlayerSession::where('refreshTokenHash', $hash)->first();

        if ($current === null) {
            return ['status' => 'invalid_token'];
        }

        if ($current->terminatedAt !== null) {
            // This exact refresh token was already rotated away once before — its reuse
            // means it leaked. Revoke every token descended from the same login.
            PlayerSession::where('refreshTokenFamily', $current->refreshTokenFamily)
                ->whereNull('terminatedAt')
                ->update(['terminatedAt' => now()]);

            return ['status' => 'reuse_detected'];
        }

        if ($current->expiresAt->isPast()) {
            return ['status' => 'expired'];
        }

        $current->forceFill(['terminatedAt' => now()])->save();

        $player = Player::find($current->playerId);
        if ($player === null || $player->accountStatus === 'inactive' || $player->accountStatus === 'closed') {
            return ['status' => 'account_inactive'];
        }

        $tokens = $this->issueTokens($player, $current->msisdn, $current->channel, family: $current->refreshTokenFamily);

        return array_merge(['status' => 'signed_in'], $tokens);
    }

    /** Revokes whatever the caller presents — an access token, a refresh token, or both. */
    public function signOut(?string $accessToken, ?string $rawRefreshToken): void
    {
        if ($accessToken !== null) {
            Cache::forget("access-token:$accessToken");
        }

        if ($rawRefreshToken !== null) {
            PlayerSession::where('refreshTokenHash', hash('sha256', $rawRefreshToken))
                ->whereNull('terminatedAt')
                ->update(['terminatedAt' => now()]);
        }
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    private function issueTokens(Player $player, string $msisdn, string $channel, ?int $family): array
    {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        $row = PlayerSession::create([
            'playerId' => $player->id,
            'msisdn' => $msisdn,
            // Never the raw bearer value at rest, even though the column name doesn't say
            // "Hash" — Cache below is the only place the live access token exists in full.
            'sessionToken' => hash('sha256', $accessToken),
            'refreshTokenHash' => hash('sha256', $refreshToken),
            'refreshTokenFamily' => $family, // filled in below on first issuance
            'channel' => $channel,
            'expiresAt' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        if ($family === null) {
            $row->forceFill(['refreshTokenFamily' => $row->id])->save();
        }

        Cache::put("access-token:$accessToken", $player->id, self::ACCESS_TOKEN_TTL_SECONDS);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
        ];
    }
}
