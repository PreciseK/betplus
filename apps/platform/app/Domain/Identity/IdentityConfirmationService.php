<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Models\NameLookupCache;
use App\Models\Player;
use App\Models\SignupSession;

/**
 * Story 1.8 — confirm identity from the OPay wallet (REQ-ID-010..013).
 * Runs after RegistrationService::verify() has proven OTP ownership.
 */
final class IdentityConfirmationService
{
    public function __construct(
        private readonly OpayGateway $opay,
        private readonly SessionService $sessions,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    /** @return array{status: 'confirm', registered_name: string}|array{status: 'no_wallet'|'error'|'otp_not_verified'} */
    public function confirmIdentity(string $rawMsisdn): array
    {
        $msisdn = PhoneNumber::toE164($rawMsisdn);
        $session = $this->requireVerifiedSession($msisdn);
        if ($session === null) {
            return ['status' => 'otp_not_verified'];
        }

        // Each lookup is billable — reuse a fresh cached result rather than re-querying OPay.
        $cached = NameLookupCache::where('msisdn', $msisdn)
            ->where('expiresAt', '>', now())
            ->latest('id')
            ->first();

        if ($cached === null) {
            $result = $this->opay->nameLookup($msisdn);
            $cached = NameLookupCache::create([
                'msisdn' => $msisdn,
                'returnedName' => $result['status'] === 'found'
                    ? trim($result['firstName'] . ' ' . $result['lastName'])
                    : null,
                'lookupStatus' => match ($result['status']) {
                    'found' => 'success',
                    'no_wallet' => 'not_found',
                    default => 'error',
                },
                'rawResponse' => $result,
                // Refusals are cached too (shorter TTL) so a retry loop can't be used to
                // re-probe billable lookups; success is cached longer.
                'expiresAt' => now()->addMinutes($result['status'] === 'found' ? 1440 : 15),
            ]);
        }

        if ($cached->lookupStatus === 'error') {
            // Transient/unreachable — recoverable, session progress is untouched (UX-DR12).
            return ['status' => 'error'];
        }
        if ($cached->lookupStatus === 'not_found') {
            // Refusal is on the record via nameLookupCache for rate measurement (REQ-ID-012).
            return ['status' => 'no_wallet'];
        }

        $session->forceFill([
            'registeredName' => $cached->returnedName,
            'nameLookupRaw' => $cached->rawResponse,
            'lookupCompletedAt' => now(),
        ])->save();

        return ['status' => 'confirm', 'registered_name' => $cached->returnedName];
    }

    /** @return array{status: string, player_id?: int, access_token?: string, refresh_token?: string, expires_in?: int} */
    public function completeRegistration(string $rawMsisdn, string $channel = 'web'): array
    {
        $msisdn = PhoneNumber::toE164($rawMsisdn);
        $session = $this->requireVerifiedSession($msisdn);
        if ($session === null || $session->registeredName === null) {
            return ['status' => 'identity_not_confirmed'];
        }
        if (Player::where('msisdn', $msisdn)->exists()) {
            return ['status' => 'already_registered'];
        }

        // The OPay-returned name is authoritative and never player-editable (REQ-ID-013) —
        // there is deliberately no "name" input on this endpoint.
        $player = Player::create([
            'msisdn' => $msisdn,
            'registeredName' => $session->registeredName,
            'registrationChannel' => $channel,
            'kycTier' => 0,
        ]);
        $session->forceFill(['consumedAt' => now()])->save();

        // Registration ends signed in — nobody should have to log in again immediately
        // after finishing account creation. This also gives Story 1.9 (NIN) a token to
        // continue against, since the signupSession this all rode on is now consumed.
        $tokens = $this->sessions->issueTokensFor($player, $msisdn, $channel);

        // Story 6.10 — start of the acquisition-through-first-paid-play funnel (REQ-ANL-007).
        $this->analytics->record('player_registered', $player, $channel);

        return array_merge(['status' => 'registered', 'player_id' => $player->id], $tokens);
    }

    private function requireVerifiedSession(string $msisdn): ?SignupSession
    {
        return SignupSession::verifiedAndUnconsumed($msisdn)->first();
    }
}
