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

    /** POST /v1/wallet/deposits — single step: verify + credit, or reject. No OTP. */
    public function deposit(CreateDepositRequest $request): JsonResponse
    {
        $amountKobo = (int) $request->string('quote_id')->toString();
        $result = $this->funding->collect($this->player(), $amountKobo, $request->string('reference')->toString());

        return response()->json($result, $result['status'] === 'paid' ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function transactionShape(Collection $collection): array
    {
        $history = [
            ['status' => 'Deposit requested', 'at' => $collection->createdAt->toIso8601String(), 'detail' => 'OPay wallet and merchant balance verified.'],
        ];
        if ($collection->status === 'paid') {
            $history[] = ['status' => 'Payment confirmed', 'at' => $collection->paidAt->toIso8601String(), 'detail' => 'Play Balance credited immediately.'];
        } elseif ($collection->status === 'failed') {
            $history[] = ['status' => 'Payment failed', 'at' => $collection->updatedAt->toIso8601String(), 'detail' => 'Could not be verified against OPay.'];
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
