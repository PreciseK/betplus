<?php

declare(strict_types=1);

namespace App\Domain\Ussd;

use App\Domain\Identity\IdentityConfirmationService;
use App\Domain\Identity\PhoneNumber;
use App\Domain\Identity\SessionService;
use App\Models\Player;
use App\Models\SignupSession;

/**
 * Story 8.2 / REQ-ID-004 — "the MSISDN is supplied by the telco gateway and treated
 * as implicitly verified." No OTP round trip exists on this path at all: by the time
 * this runs, VerifyUssdGatewaySignature has already proven the request came from a
 * trusted gateway asserting this MSISDN, which is the thing OTP proves on web/app.
 * Reuses RegistrationService's downstream steps (OPay name lookup, registeredName
 * confirmation, Player creation) rather than duplicating them — only the trust
 * bootstrap differs.
 */
final class UssdIdentityService
{
    private const IMPLICIT_SESSION_TTL_MINUTES = 10;

    public function __construct(
        private readonly IdentityConfirmationService $identity,
        private readonly SessionService $sessions,
    ) {
    }

    /**
     * @return array{status: 'signed_in', access_token: string, refresh_token: string, expires_in: int, registered_name: string}
     *       | array{status: 'confirm_identity', registered_name: string}
     *       | array{status: 'no_wallet'|'error'}
     */
    public function identify(string $rawMsisdn): array
    {
        $msisdn = PhoneNumber::toE164($rawMsisdn);

        $player = Player::where('msisdn', $msisdn)->first();
        if ($player !== null) {
            // REQ-ID-004 again, for a RETURNING player: gateway trust is per-request,
            // not just at registration — no OTP here either.
            $tokens = $this->sessions->issueTokensFor($player, $msisdn, 'ussd');

            return array_merge(['status' => 'signed_in', 'registered_name' => $player->registeredName], $tokens);
        }

        $this->beginImplicitlyVerifiedSession($msisdn);
        $result = $this->identity->confirmIdentity($msisdn);

        return match ($result['status']) {
            'confirm' => ['status' => 'confirm_identity', 'registered_name' => $result['registered_name']],
            default => ['status' => $result['status']],
        };
    }

    /** @return array{status: string, player_id?: int, access_token?: string, refresh_token?: string, expires_in?: int} */
    public function completeRegistration(string $rawMsisdn): array
    {
        return $this->identity->completeRegistration(PhoneNumber::toE164($rawMsisdn), 'ussd');
    }

    /**
     * REQ-ID-004's trust substitutes for OTP ownership-proof — this session row is
     * otpVerifiedAt-set from the start rather than after a code round trip, so it
     * satisfies SignupSession::scopeVerifiedAndUnconsumed() the same way a real OTP
     * verification would (IdentityConfirmationService has no idea the difference).
     */
    private function beginImplicitlyVerifiedSession(string $msisdn): SignupSession
    {
        $now = now();

        return SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => null,
            'otpVerifiedAt' => $now,
            'expiresAt' => $now->addMinutes(self::IMPLICIT_SESSION_TTL_MINUTES),
        ]);
    }
}
