<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Wallet\FundingService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateDepositRequest;
use App\Http\Requests\Api\V1\FundingQuoteRequest;
use App\Models\Collection;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly FundingService $funding,
    ) {
    }

    /** GET /v1/wallet — both balances (Betplus_PRD.md §12.3). */
    public function show(): JsonResponse
    {
        $player = $this->player();
        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            'bonus_balance_kobo' => (int) $wallet->bonusBalanceKobo,
            'currency' => 'NGN',
            'registered_source_label' => 'OPay wallet ending ' . substr($player->msisdn, -4),
        ]);
    }

    /** GET /v1/wallet/transactions */
    public function transactions(): JsonResponse
    {
        $player = $this->player();
        $rows = Collection::where('playerId', $player->id)->latest('id')->get();

        return response()->json([
            'transactions' => $rows->map(fn (Collection $c) => $this->transactionShape($c))->values(),
        ]);
    }

    public function quote(FundingQuoteRequest $request): JsonResponse
    {
        return response()->json($this->funding->quote($this->player(), (int) $request->input('amount_kobo')));
    }

    /**
     * POST /v1/wallet/deposits/init — step 1 of OPay Server-Side Collection.
     * Initiates payment on OPay and returns orderNo and challenge type (INPUT_PIN / INPUT_OTP).
     */
    public function initDeposit(Request $request): JsonResponse
    {
        $amountKobo = (int) ($request->input('amount_kobo') ?? $request->input('quote_id'));
        $reference = $request->filled('reference') ? $request->string('reference')->toString() : null;

        $result = $this->funding->initiateFunding($this->player(), $amountKobo, $reference);
        $ok = in_array($result['status'], ['initiated', 'paid'], true);

        return response()->json($result, $ok ? 200 : 422);
    }

    /**
     * POST /v1/wallet/deposits/pin — step 2 of OPay Server-Side Collection (PIN).
     * Authorizes the pending deposit with the player's 4-digit OPay PIN (USSD / Fast Web).
     */
    public function submitPin(Request $request): JsonResponse
    {
        $orderNo = $request->string('order_no')->toString();
        $pin = $request->string('pin')->toString();

        if ($orderNo === '' || $pin === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing order_no or pin'], 422);
        }

        $result = $this->funding->submitFundingPin($this->player(), $orderNo, $pin);
        $ok = $result['status'] === 'paid';

        return response()->json($result, $ok ? 200 : 422);
    }

    /**
     * POST /v1/wallet/deposits/otp — step 2 of OPay Server-Side Collection (OTP).
     * Authorizes the pending deposit with SMS OTP (Web fallback).
     */
    public function submitOtp(Request $request): JsonResponse
    {
        $orderNo = $request->string('order_no')->toString();
        $otp = $request->string('otp')->toString();

        if ($orderNo === '' || $otp === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing order_no or otp'], 422);
        }

        $result = $this->funding->submitFundingOtp($this->player(), $orderNo, $otp);
        $ok = $result['status'] === 'paid';

        return response()->json($result, $ok ? 200 : 422);
    }

    /** POST /v1/wallet/deposits — synchronous verify + credit (legacy / sandbox fallback). */
    public function deposit(CreateDepositRequest $request): JsonResponse
    {
        $amountKobo = (int) $request->string('quote_id')->toString();
        $result = $this->funding->collect($this->player(), $amountKobo, $request->string('reference')->toString());

        $accepted = in_array($result['status'], ['paid', 'pending_review'], true);

        return response()->json($result, $accepted ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function transactionShape(Collection $collection): array
    {
        $history = [
            ['status' => 'Deposit requested', 'at' => $collection->createdAt->toIso8601String(), 'detail' => 'OPay collection initiated.'],
        ];
        if ($collection->status === 'paid') {
            $history[] = ['status' => 'Payment confirmed', 'at' => $collection->paidAt?->toIso8601String() ?? $collection->updatedAt->toIso8601String(), 'detail' => 'Play Balance credited successfully.'];
        } elseif ($collection->status === 'failed') {
            $history[] = ['status' => 'Payment failed', 'at' => $collection->updatedAt->toIso8601String(), 'detail' => 'Declined by OPay or user canceled.'];
        } elseif ($collection->status === 'pending_review') {
            $history[] = ['status' => 'Under review', 'at' => $collection->updatedAt->toIso8601String(), 'detail' => 'Amount exceeds the auto-credit threshold; awaiting back-office approval.'];
        }

        return [
            'reference' => $collection->reference,
            'type' => 'OPay deposit',
            'provider' => 'OPay',
            'occurred_at' => $collection->createdAt->toIso8601String(),
            'amount_kobo' => $collection->amountKobo,
            'fee_kobo' => $collection->feeKobo,
            'status' => match ($collection->status) {
                'paid' => 'paid',
                'failed' => 'failed',
                default => 'pending',
            },
            'status_history' => $history,
        ];
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
