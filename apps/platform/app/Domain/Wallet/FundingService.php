<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Payments\Providers\Opay\OpayCollectionGateway;
use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\Collection;
use App\Models\Player;
use Illuminate\Support\Str;

/**
 * Story 2.3/2.4 — Fund the Play Balance from an OPay wallet.
 *
 * Supports two complementary collection mechanisms:
 * 1. OPay Server-Side Collections API (REQ-PAY-001, REQ-PAY-016):
 *    - initiateFunding(): POST /payment/create with BankAccount
 *    - submitFundingPin(): POST /payment/action/input-pin (for USSD and fast in-app)
 *    - submitFundingOtp(): POST /payment/input-otp (for web SMS fallback)
 *    - confirmCollectionPaid(): idempotently credits the double-entry ledger.
 *
 * 2. Synchronous trust-advance check (legacy collect()):
 *    - Verifies wallet existence via payout name-lookup and float balance,
 *      retained for zero-shortfall / offline sandbox compatibility.
 */
final class FundingService
{
    // No deposit fee exists anywhere in the PRD — always "No fee", verified
    // (UX-DR13), not just defaulted.
    private const FEE_KOBO = 0;

    public function __construct(
        private readonly OpayGateway $opay,
        private readonly OpayCollectionGateway $collections,
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
     * Initiates a 2-step payment collection using OPay Server-Side APIs.
     * Returns the orderNo and next challenge type (e.g. INPUT_PIN, INPUT_OTP).
     *
     * @return array{status: string, reference?: string, order_no?: string, action_type?: string, amount_kobo?: int, message?: string}
     */
    public function initiateFunding(Player $player, int $amountKobo, ?string $reference = null): array
    {
        if ($amountKobo <= 0) {
            return ['status' => 'invalid_amount', 'message' => 'Amount must be positive.'];
        }

        try {
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertDepositWithinLimits($player, $amountKobo);
            if ($player->ninHash !== null) {
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

        $existing = Collection::where('reference', $ref)->first();
        if ($existing !== null && (int) $existing->playerId !== $player->id) {
            return ['status' => 'reference_conflict', 'message' => 'This reference is already in use.'];
        }
        if ($existing !== null && $existing->status === 'paid') {
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'reference' => $existing->reference,
                'order_no' => (string) $existing->providerCollectionId,
                'action_type' => 'NONE',
                'amount_kobo' => (int) $existing->amountKobo,
            ];
        }

        $initResult = $this->collections->createPayment($ref, $amountKobo, $player->msisdn, $player->registeredName);
        if ($initResult['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => $initResult['message'] ?? 'Unable to initiate OPay payment',
            ];
        }

        $orderNo = $initResult['orderNo'] ?? '';
        $actionType = $initResult['actionType'] ?? 'INPUT_PIN';

        Collection::create([
            'playerId' => $player->id,
            'reference' => $ref,
            'amountKobo' => $amountKobo,
            'providerCollectionId' => $orderNo !== '' ? $orderNo : null,
            'authChallengeType' => $actionType,
            'status' => 'pending_auth',
            'paidAt' => null,
        ]);

        return [
            'status' => 'initiated',
            'reference' => $ref,
            'order_no' => $orderNo,
            'action_type' => $actionType,
            'amount_kobo' => $amountKobo,
        ];
    }

    /**
     * Submits player's 4-digit OPay PIN to authorize the payment in-turn (USSD & Fast Web).
     *
     * @return array{status: string, credited_kobo?: int, play_balance_kobo?: int, reference?: string, message?: string}
     */
    public function submitFundingPin(Player $player, string $orderNo, string $pin): array
    {
        $collection = Collection::where('providerCollectionId', $orderNo)
            ->where('playerId', $player->id)
            ->first();

        if ($collection === null) {
            return ['status' => 'error', 'message' => 'Deposit order not found.'];
        }

        if ($collection->status === 'paid') {
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'credited_kobo' => (int) $collection->amountKobo,
                'play_balance_kobo' => (int) $wallet->playBalanceKobo,
                'reference' => $collection->reference,
            ];
        }

        $result = $this->collections->submitPin($orderNo, $pin);

        if ($result['status'] === 'paid') {
            $this->confirmCollectionPaid($collection);
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'credited_kobo' => (int) $collection->amountKobo,
                'play_balance_kobo' => (int) $wallet->playBalanceKobo,
                'reference' => $collection->reference,
            ];
        }

        if ($result['status'] === 'failed') {
            $collection->update(['status' => 'failed']);

            return [
                'status' => 'failed',
                'message' => $result['message'] ?? 'PIN authorization failed.',
                'reference' => $collection->reference,
            ];
        }

        return [
            'status' => $result['status'],
            'message' => $result['message'] ?? 'Processing payment.',
            'reference' => $collection->reference,
        ];
    }

    /**
     * Submits player's SMS OTP to authorize the payment (Web fallback).
     *
     * @return array{status: string, credited_kobo?: int, play_balance_kobo?: int, reference?: string, message?: string}
     */
    public function submitFundingOtp(Player $player, string $orderNo, string $otp): array
    {
        $collection = Collection::where('providerCollectionId', $orderNo)
            ->where('playerId', $player->id)
            ->first();

        if ($collection === null) {
            return ['status' => 'error', 'message' => 'Deposit order not found.'];
        }

        if ($collection->status === 'paid') {
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'credited_kobo' => (int) $collection->amountKobo,
                'play_balance_kobo' => (int) $wallet->playBalanceKobo,
                'reference' => $collection->reference,
            ];
        }

        $result = $this->collections->submitOtp($orderNo, $otp);

        if ($result['status'] === 'paid') {
            $this->confirmCollectionPaid($collection);
            $wallet = $this->wallet->walletFor($player);

            return [
                'status' => 'paid',
                'credited_kobo' => (int) $collection->amountKobo,
                'play_balance_kobo' => (int) $wallet->playBalanceKobo,
                'reference' => $collection->reference,
            ];
        }

        if ($result['status'] === 'failed') {
            $collection->update(['status' => 'failed']);

            return [
                'status' => 'failed',
                'message' => $result['message'] ?? 'OTP authorization failed.',
                'reference' => $collection->reference,
            ];
        }

        return [
            'status' => $result['status'],
            'message' => $result['message'] ?? 'Processing payment.',
            'reference' => $collection->reference,
        ];
    }

    /**
     * Idempotently marks a Collection paid and credits the double-entry Play Balance.
     */
    public function confirmCollectionPaid(Collection $collection): void
    {
        if ($collection->status === 'paid') {
            return;
        }

        $collection->update([
            'status' => 'paid',
            'paidAt' => now(),
        ]);

        /** @var Player $player */
        $player = Player::findOrFail($collection->playerId);

        $this->wallet->creditPlayBalanceFromOpay(
            $player,
            (int) $collection->amountKobo,
            'collection',
            $collection->id,
            (string) config('jurisdiction.stub_state_code', 'LAG'),
        );

        $this->analytics->record('deposit_completed', $player, 'web', properties: [
            'amount_kobo' => (int) $collection->amountKobo,
            'reference' => $collection->reference,
        ]);
    }

    /**
     * Synchronous trust collection (legacy / sandbox fallback).
     *
     * @return array{status: string, credited_kobo?: int, play_balance_kobo?: int, reference?: string, message?: string}
     */
    public function collect(Player $player, int $amountKobo, ?string $reference = null): array
    {
        if ($amountKobo <= 0) {
            return ['status' => 'invalid_amount', 'message' => 'Amount must be positive.'];
        }

        try {
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertDepositWithinLimits($player, $amountKobo);
            if ($player->ninHash !== null) {
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
            (string) config('jurisdiction.stub_state_code', 'LAG'),
        );

        $this->analytics->record('deposit_completed', $player, 'web', properties: ['amount_kobo' => $amountKobo]);

        $wallet = $this->wallet->walletFor($player);

        return [
            'status' => 'paid',
            'credited_kobo' => $amountKobo,
            'play_balance_kobo' => (int) $wallet->playBalanceKobo,
            'reference' => $collection->reference,
        ];
    }

    /** Loose match: same words present, case/accent/whitespace-insensitive. */
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
