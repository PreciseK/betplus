<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\Identity\Vault\IdentityVaultService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Jobs\PollCollectionStatusJob;
use App\Models\Collection;
use App\Models\KycRecord;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 2.3/2.4 — fund the Play Balance from an OPay wallet, and resolve it safely
 * regardless of whether the OTP-submit response, a provider callback, or a status
 * poll is what confirms it. REQ-PAY-004 requires a verified BVN, DOB and customer
 * name on file before BankAccount collections can be used at all — see
 * BvnVerificationService; there is no OPay-side alternative.
 */
final class FundingService
{
    // No deposit fee exists anywhere in the PRD — always "No fee", verified
    // (UX-DR13), not just defaulted.
    private const FEE_KOBO = 0;

    public function __construct(
        private readonly OpayGateway $opay,
        private readonly IdentityVaultService $vault,
        private readonly WalletService $wallet,
        private readonly LimitsService $limits,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    /** @return array{quote_id: string, amount_kobo: int, fee_kobo: int, fee_verified: true, source_label: string, destination_label: string, expected_timing: string, reversible: false} */
    public function quote(Player $player, int $amountKobo): array
    {
        return [
            'quote_id' => (string) $amountKobo,
            'amount_kobo' => $amountKobo,
            'fee_kobo' => self::FEE_KOBO,
            'fee_verified' => true,
            'source_label' => 'OPay wallet ending ' . substr($player->msisdn, -4),
            'destination_label' => 'Play Balance',
            'expected_timing' => 'Usually within 2 minutes after OPay confirms payment',
            'reversible' => false,
        ];
    }

    /** @return array{status: string, collection_id?: int, otp_required?: true} */
    public function createCollection(Player $player, string $quoteId): array
    {
        $amountKobo = (int) $quoteId;
        if ($amountKobo <= 0) {
            return ['status' => 'invalid_quote'];
        }

        // Epic 5 — a cool-off/self-exclusion or a registry exclusion blocks deposit,
        // not just play (REQ-RG-004/005/015); deposit limits apply the same way stake
        // limits do (REQ-RG-002).
        try {
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertDepositWithinLimits($player, $amountKobo);
            if ($player->ninHash !== null) {
                // Registry matching needs a verified NIN; a BVN-only depositor who
                // hasn't reached NIN verification yet has no registry record to check
                // against, and REQ-RG-012 ties matching to NIN specifically — the BVN
                // check just below still gates deposit on identity verification anyway.
                $this->registry->assertClear($player);
            }
        } catch (TicketEligibilityException $e) {
            return ['status' => match ($e->errorCode) {
                'LIMIT_REACHED' => 'limit_exceeded',
                'EXCLUDED' => 'protection_active',
                default => 'registry_unavailable',
            }];
        }

        $bvnRecord = KycRecord::where('playerId', $player->id)->where('idType', 'bvn')->whereNotNull('verifiedAt')->latest('id')->first();
        $ninRecord = KycRecord::where('playerId', $player->id)->where('idType', 'nin')->whereNotNull('verifiedAt')->latest('id')->first();
        if ($bvnRecord === null || $ninRecord === null || $ninRecord->dateOfBirth === null) {
            return ['status' => 'bvn_required'];
        }

        $bvn = $this->vault->retrieve($bvnRecord->verificationRef, self::class);
        if ($bvn === null) {
            return ['status' => 'error'];
        }

        $reference = (string) Str::ulid();
        $collection = Collection::create([
            'playerId' => $player->id,
            'reference' => $reference,
            'amountKobo' => $amountKobo,
        ]);

        $result = $this->opay->createCollection(
            $reference,
            $player->msisdn,
            $amountKobo,
            (string) config('opay.collection_bank_code'),
            $bvn,
            $ninRecord->dateOfBirth->format('Y-m-d'),
            $player->registeredName,
        );

        if ($result['status'] !== 'otp_required') {
            $collection->forceFill(['status' => 'failed'])->save();
            return ['status' => 'error'];
        }

        $collection->forceFill([
            'status' => 'processing',
            'providerCollectionId' => $result['providerCollectionId'],
        ])->save();

        // REQ-PAY-015 — a safety net in case neither the OTP response nor a callback
        // ever resolves this. Fires once, 90s out; it re-dispatches itself with backoff
        // from inside handle() until resolved or the 24h window closes.
        PollCollectionStatusJob::dispatch($collection->id)->delay(now()->addSeconds(90));

        return ['status' => 'otp_required', 'collection_id' => $collection->id, 'otp_required' => true];
    }

    /**
     * Two-phase, mirroring the ticket-creation pattern elsewhere in this codebase
     * (architecture.md D-08): claim the row in a short locked transaction first, make
     * the OPay HTTP call with no lock held, then finalise in a second short transaction.
     * Never hold a DB row lock across a network call — REQ-QA-008 needs exactly-once
     * under 100 concurrent calls, which a lock held for the HTTP round trip would give
     * for free but at the cost of serialising every request on OPay's response time.
     *
     * @return array{status: string}
     */
    public function submitCollectionOtp(Player $player, int $collectionId, string $otp): array
    {
        $claim = $this->claim($collectionId, $player->id);
        if ($claim !== 'claimed') {
            return ['status' => $claim];
        }

        $result = $this->opay->submitCollectionOtp(Collection::findOrFail($collectionId)->reference, $otp);

        return $this->finalize($collectionId, $result['status']);
    }

    /**
     * Same claim/finalize path as submitCollectionOtp — a provider callback and a
     * player-driven OTP response racing each other must still only credit once
     * (REQ-PAY-013). $reference is Betplus's own, matched against `collection.reference`.
     *
     * @return array{status: string}
     */
    public function resolveByReference(string $reference, string $providerStatus): array
    {
        $collection = Collection::where('reference', $reference)->first();
        if ($collection === null) {
            return ['status' => 'unknown_reference'];
        }

        $claim = $this->claim($collection->id, $collection->playerId);
        if ($claim !== 'claimed') {
            return ['status' => $claim];
        }

        return $this->finalize($collection->id, $providerStatus);
    }

    /** @return 'claimed'|'paid'|'failed'|'not_found'|'already_processing' */
    private function claim(int $collectionId, ?int $expectedPlayerId): string
    {
        return DB::transaction(function () use ($collectionId, $expectedPlayerId) {
            $query = Collection::where('id', $collectionId)->lockForUpdate();
            if ($expectedPlayerId !== null) {
                $query->where('playerId', $expectedPlayerId);
            }
            $collection = $query->first();

            if ($collection === null) {
                return 'not_found';
            }

            return match ($collection->status) {
                'paid' => 'paid', // idempotent — a replay never re-credits (REQ-PAY-013)
                'failed' => 'failed',
                'processing' => tap('claimed', function () use ($collection) {
                    $collection->forceFill(['status' => 'finalizing'])->save();
                }),
                // Someone else (an OTP response, a callback, or a status poll) already
                // claimed it and is mid-flight — collapse to one effect (REQ-QA-008),
                // don't call OPay or credit a second time.
                default => 'already_processing',
            };
        });
    }

    /** @return array{status: string} */
    private function finalize(int $collectionId, string $opayStatus): array
    {
        return DB::transaction(function () use ($collectionId, $opayStatus) {
            $collection = Collection::where('id', $collectionId)->lockForUpdate()->first();
            if ($collection === null) {
                // Only reachable if the row was deleted after claim() confirmed it
                // existed — collections are never deleted, so this is a defensive
                // guard, not an expected path.
                return ['status' => 'not_found'];
            }
            if ($collection->status !== 'finalizing') {
                // Already resolved by whichever of OTP-response/callback/status-poll got
                // here first — report the current truth, not a stale $opayStatus.
                return ['status' => $collection->status];
            }

            if ($opayStatus === 'paid') {
                $collection->forceFill(['status' => 'paid', 'paidAt' => now()])->save();
                $player = Player::findOrFail($collection->playerId);
                $this->wallet->creditPlayBalanceFromOpay(
                    $player,
                    $collection->amountKobo,
                    'collection',
                    $collection->id,
                );

                // Story 6.10 — funding funnel step (REQ-ANL-007).
                $this->analytics->record('deposit_completed', $player, 'web', properties: ['amount_kobo' => $collection->amountKobo]);

                return ['status' => 'paid'];
            }

            if ($opayStatus === 'failed') {
                $collection->forceFill(['status' => 'failed'])->save();

                return ['status' => 'failed'];
            }

            // Unconfirmed (processing/error) — release the claim back to 'processing' so
            // the status-poll job or a later callback can resolve it, rather than
            // leaving it stuck mid-claim indefinitely.
            $collection->forceFill(['status' => 'processing'])->save();

            return ['status' => $opayStatus];
        });
    }
}
