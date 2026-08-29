<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payout\PayoutService;
use App\Domain\Payout\PayoutStatusMapper;
use App\Domain\Wallet\TurnoverService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QuoteWithdrawalRequest;
use App\Http\Requests\Api\V1\RequestWithdrawalRequest;
use App\Models\Payout;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PayoutController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TurnoverService $turnover,
        private readonly PayoutService $payouts,
        private readonly PayoutStatusMapper $mapper,
    ) {
    }

    /** GET /v1/payouts */
    public function context(): JsonResponse
    {
        $player = $this->player();
        $wallet = $this->wallet->walletFor($player);
        $turnover = $this->turnover->positionFor($player);

        $payouts = Payout::where('playerId', $player->id)->latest('id')->get();

        return response()->json([
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'destination_label' => 'OPay wallet ending ' . substr($player->msisdn, -4),
            'destination_name' => $player->registeredName,
            'turnover' => [
                'deposit_amount_kobo' => $turnover['requiredKobo'],
                'staked_kobo' => $turnover['stakedKobo'],
                'required_stake_kobo' => $turnover['requiredKobo'],
                'released_play_balance_kobo' => $turnover['releasedPlayBalanceKobo'],
            ],
            'manual_review_threshold_kobo' => (int) config('payout.manual_review_threshold_kobo'),
            'payouts' => $payouts->map(fn (Payout $p) => $this->payoutShape($p))->values(),
        ]);
    }

    /** POST /v1/payouts/quote */
    public function quote(QuoteWithdrawalRequest $request): JsonResponse
    {
        try {
            $quote = $this->payouts->quoteWithdrawal(
                $this->player(),
                $request->string('source')->toString(),
                (int) $request->input('amount_kobo'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'quote_id' => $quote['quoteId'],
            'source' => $quote['source'],
            'source_label' => $quote['sourceLabel'],
            'amount_kobo' => $quote['amountKobo'],
            'fee_kobo' => $quote['feeKobo'],
            'fee_verified' => $quote['feeVerified'],
            'tax_already_handled' => $quote['taxAlreadyHandled'],
            'destination_label' => $quote['destinationLabel'],
            'destination_name' => $quote['destinationName'],
            'expected_timing' => $quote['expectedTiming'],
            'manual_review_required' => $quote['manualReviewRequired'],
        ]);
    }

    /** POST /v1/payouts */
    public function request(RequestWithdrawalRequest $request): JsonResponse
    {
        try {
            $payout = $this->payouts->requestWithdrawal($this->player(), $request->string('quote_id')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payoutShape($payout));
    }

    /** @return array<string, mixed> */
    private function payoutShape(Payout $payout): array
    {
        $displayStatus = $this->mapper->toDisplayStatus($payout->providerStatus);
        $ticket = $payout->ticketId !== null ? Ticket::with('outcome')->find($payout->ticketId) : null;
        $outcome = $ticket?->outcome;

        return array_filter([
            'reference' => $payout->reference,
            'kind' => $payout->kind,
            'created_at' => $payout->createdAt->toIso8601String(),
            'amount_kobo' => $payout->amountKobo,
            'source_label' => $payout->sourceLabel,
            'destination_label' => $payout->destinationLabel,
            'provider_status' => $payout->providerStatus,
            'display_status' => $displayStatus,
            'status_expectation' => $this->statusExpectation($payout, $displayStatus),
            'opay_order_number' => $payout->opayOrderNo,
            'game' => $ticket !== null ? 'BlackRed' : null,
            'ticket_reference' => $ticket?->reference,
            'gross_prize_kobo' => $outcome?->grossPrizeKobo,
            'tax_withheld_kobo' => $outcome?->taxWithheldKobo,
            'tax_rate_basis_points' => $outcome?->taxRateBasisPoints,
            'tax_basis_label' => $outcome?->taxBasisLabel,
            'net_paid_kobo' => $payout->kind === 'automatic-prize' ? $payout->amountKobo : null,
            'funds_remain_in_winnings' => $payout->providerStatus !== 'SUCCESS',
            'status_history' => $this->statusHistory($payout),
        ], fn ($value) => $value !== null);
    }

    private function statusExpectation(Payout $payout, string $displayStatus): string
    {
        return match ($displayStatus) {
            'paid' => 'Paid to OPay.',
            'manual-review' => 'The provider status is being reviewed manually. It is not treated as a failure.',
            'needs-attention' => 'The transfer could not be confirmed automatically. Your money is protected.',
            default => 'Usually confirmed within 90 seconds. You can leave this screen and check Activity.',
        };
    }

    /** @return list<array{status:string, at:string, detail:string}> */
    private function statusHistory(Payout $payout): array
    {
        $history = [[
            'status' => $payout->kind === 'automatic-prize' ? 'Winnings credited' : 'Withdrawal requested',
            'at' => $payout->createdAt->toIso8601String(),
            'detail' => $payout->kind === 'automatic-prize'
                ? 'Net prize credited to Winnings Balance.'
                : 'Request received and linked to this reference.',
        ]];

        if ($payout->dispatchedAt !== null) {
            $history[] = [
                'status' => 'Transfer processing',
                'at' => $payout->dispatchedAt->toIso8601String(),
                'detail' => 'Betplus is waiting for OPay confirmation. No prize is lost.',
            ];
        }
        if ($payout->confirmedAt !== null) {
            $history[] = [
                'status' => $payout->providerStatus === 'SUCCESS' ? 'Paid' : 'Needs attention',
                'at' => $payout->confirmedAt->toIso8601String(),
                'detail' => $payout->providerStatus === 'SUCCESS'
                    ? 'OPay confirmed wallet credit.'
                    : 'An unrecognised or failed provider status was recorded; funds were protected.',
            ];
        }

        return $history;
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
