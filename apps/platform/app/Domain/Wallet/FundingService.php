<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\Collection;
use App\Models\Player;
use Illuminate\Support\Str;

/**
 * Story 2.3/2.4 — fund the Play Balance from an OPay wallet.
 *
 * There is no Collections API doc in this repo — only the OPay Payout API
 * Developer Guide (repo root). That document has no endpoint at all for pulling
 * money from a customer; every endpoint in it is merchant-to-customer (payout,
 * wallet-to-wallet push, balance query, wallet/bank validation). A prior build of
 * this class called an invented `/payment/create` + `/payment/input-otp` pair with
 * an OTP step — those endpoints are not in any doc available here and have been
 * removed. This build instead does the only thing the documented API can actually
 * support: verify the phone number resolves to a real OPay wallet matching the
 * player's registered name (§2.6 "Opay wallet validate"), verify BetPlus's own
 * merchant balance can cover the amount (§2.4 "Merchant balance query"), and credit
 * Play Balance bounded by both checks — synchronously, no OTP, no callback, no
 * polling job, because nothing is left pending after the request returns.
 *
 * That combination is a trust check, not proof of a payment — §2.4's balance query
 * returns one aggregate total with no transaction list or sender info, so nothing
 * documented here can attribute a specific inbound transfer to this specific
 * request. Above config('funding.manual_review_threshold_kobo'), collect() holds
 * the deposit for back-office review instead of crediting immediately, the same
 * compensating control DispatchPayout applies on the payout side above
 * config('payout.manual_review_threshold_kobo').
 */
final class FundingService
{
    // No deposit fee exists anywhere in the PRD — always "No fee", verified
    // (UX-DR13), not just defaulted.
    private const FEE_KOBO = 0;

    public function __construct(
        private readonly OpayGateway $opay,
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
            'expected_timing' => 'Immediate',
            'reversible' => false,
        ];
    }

    /**
     * status is one of: paid, pending_review, invalid_amount, limit_exceeded,
     * protection_active, registry_unavailable, reference_conflict,
     * wallet_unverified, float_unavailable.
     *
     * @return array{status: string, credited_kobo?: int, play_balance_kobo?: int, reference?: string, message?: string}
     */
    public function collect(Player $player, int $amountKobo, ?string $reference = null): array
    {
        if ($amountKobo <= 0) {
            return ['status' => 'invalid_amount', 'message' => 'Amount must be positive.'];
        }

        // Epic 5 — a cool-off/self-exclusion or a registry exclusion blocks deposit,
        // not just play (REQ-RG-004/005/015); deposit limits apply the same way stake
        // limits do (REQ-RG-002).
        try {
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertDepositWithinLimits($player, $amountKobo);
            if ($player->ninHash !== null) {
                // Registry matching needs a verified NIN; a player who hasn't reached
                // NIN verification yet has no registry record to check against.
                $this->registry->assertClear($player);
            }
        } catch (TicketEligibilityException $e) {
            return ['status' => match ($e->errorCode) {
                'LIMIT_REACHED' => 'limit_exceeded',
                'EXCLUDED' => 'protection_active',
                default => 'registry_unavailable',
            }];
        }

        $ref = $reference ?? (string) Str::ulid();

        // `reference` is globally unique in the DB (client-supplied idempotency key),
        // but the dedup check below must never disclose ANOTHER player's amount or
        // status just because they guessed or reused that player's reference string —
        // scope to this player first (REQ-QA — see TicketNotificationController for
        // the same reference+playerId scoping pattern on tickets).
        $existing = Collection::where('reference', $ref)->first();
        if ($existing !== null && (int) $existing->playerId !== $player->id) {
            return ['status' => 'reference_conflict', 'message' => 'This reference is already in use.'];
        }
        if ($existing !== null && $existing->status === 'paid') {
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'credited_kobo' => (int) $existing->amountKobo,
                'play_balance_kobo' => (int) $wallet->playBalanceKobo,
                'reference' => $existing->reference,
            ];
        }
        if ($existing !== null && $existing->status === 'pending_review') {
            return [
                'status' => 'pending_review',
                'message' => 'This deposit is under review and will be credited to your Play Balance once approved.',
                'reference' => $existing->reference,
            ];
        }

        $walletCheck = $this->opay->nameLookup($player->msisdn);
        if ($walletCheck['status'] !== 'found') {
            return ['status' => 'wallet_unverified', 'message' => 'Could not verify an OPay wallet for this phone number.'];
        }
        if (!$this->namesResemble($walletCheck['firstName'] . ' ' . $walletCheck['lastName'], $player->registeredName)) {
            return ['status' => 'wallet_unverified', 'message' => 'OPay wallet name does not match the registered account holder.'];
        }

        $floatKobo = $this->opay->floatBalanceKobo();
        if ($floatKobo === null || $floatKobo < $amountKobo) {
            return ['status' => 'float_unavailable', 'message' => 'Unable to verify sufficient OPay merchant balance for this deposit.'];
        }

        // The wallet+float checks above only bound how much the house is willing to
        // advance on trust — they are not proof any money moved (see class doc
        // comment). Above the threshold, hold for back-office review rather than
        // credit immediately.
        if ($amountKobo >= (int) config('funding.manual_review_threshold_kobo')) {
            $collection = Collection::create([
                'playerId' => $player->id,
                'reference' => $ref,
                'amountKobo' => $amountKobo,
                'status' => 'pending_review',
                'paidAt' => null,
            ]);

            return [
                'status' => 'pending_review',
                'message' => 'This deposit is under review and will be credited to your Play Balance once approved.',
                'reference' => $collection->reference,
            ];
        }

        $collection = Collection::create([
            'playerId' => $player->id,
            'reference' => $ref,
            'amountKobo' => $amountKobo,
            'status' => 'paid',
            'paidAt' => now(),
        ]);

        $this->wallet->creditPlayBalanceFromOpay(
            $player,
            $amountKobo,
            'collection',
            $collection->id,
            (string) config('jurisdiction.stub_state_code'),
        );

        // Story 6.10 — funding funnel step (REQ-ANL-007).
        $this->analytics->record('deposit_completed', $player, 'web', properties: ['amount_kobo' => $amountKobo]);

        $wallet = $this->wallet->walletFor($player);

        return [
            'status' => 'paid',
            'credited_kobo' => $amountKobo,
            'play_balance_kobo' => (int) $wallet->playBalanceKobo,
            'reference' => $collection->reference,
        ];
    }

    /** Loose match: same words present, case/accent/whitespace-insensitive, order-independent (OPay and BetPlus name field ordering isn't guaranteed to match). */
    private function namesResemble(string $a, string $b): bool
    {
        $normalize = fn (string $s): array => array_filter(explode(' ', preg_replace('/[^a-z ]/', '', strtolower(trim($s))) ?? ''));
        $wordsA = $normalize($a);
        $wordsB = $normalize($b);

        if ($wordsA === [] || $wordsB === []) {
            return false;
        }

        $overlap = array_intersect($wordsA, $wordsB);

        return count($overlap) >= min(2, count($wordsB));
    }
}
