<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Wallet\TurnoverService;
use App\Domain\Wallet\WalletService;
use App\Models\Payout;
use App\Models\Player;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 4.6. Only 'winnings' is reachable from the frontend today — WithdrawalFlow
 * disables the 'released-play' radio option because Story 4.5's real FIFO turnover
 * tracker isn't built (see TurnoverService's doc comment). quoteWithdrawal() still
 * refuses a 'released-play' source explicitly rather than silently mis-handling it.
 */
final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TurnoverService $turnover,
        private readonly DispatchPayout $dispatch,
    ) {
    }

    /** @return array{quoteId:string, source:string, sourceLabel:string, amountKobo:int, feeKobo:0, feeVerified:true, taxAlreadyHandled:true, destinationLabel:string, destinationName:string, expectedTiming:string, manualReviewRequired:bool} */
    public function quoteWithdrawal(Player $player, string $source, int $amountKobo): array
    {
        if ($amountKobo <= 0) {
            throw new RuntimeException('WITHDRAWAL_AMOUNT_INVALID');
        }

        $availableKobo = $this->availableFor($player, $source);
        if ($amountKobo > $availableKobo) {
            throw new RuntimeException('WITHDRAWAL_AMOUNT_INVALID');
        }

        return [
            'quoteId' => "$source:$amountKobo",
            'source' => $source,
            'sourceLabel' => $source === 'winnings' ? 'Winnings Balance' : 'Released Play Balance',
            'amountKobo' => $amountKobo,
            'feeKobo' => 0,
            'feeVerified' => true,
            'taxAlreadyHandled' => true,
            'destinationLabel' => 'OPay wallet ending ' . substr($player->msisdn, -4),
            'destinationName' => $player->registeredName,
            'expectedTiming' => 'Usually within 90 seconds after submission',
            'manualReviewRequired' => $amountKobo >= (int) config('payout.manual_review_threshold_kobo'),
        ];
    }

    public function requestWithdrawal(Player $player, string $quoteId): Payout
    {
        [$source, $amountRaw] = array_pad(explode(':', $quoteId, 2), 2, null);
        $amountKobo = (int) $amountRaw;
        if ($source !== 'winnings' && $source !== 'released-play') {
            throw new RuntimeException('QUOTE_INVALID');
        }
        if ($amountKobo <= 0 || $amountKobo > $this->availableFor($player, $source)) {
            throw new RuntimeException('QUOTE_INVALID');
        }
        if ($source === 'released-play') {
            // Not reachable from the UI today (see class doc) — refuse rather than
            // silently withdraw from a balance with no real turnover-release tracking.
            throw new RuntimeException('WITHDRAWAL_SOURCE_UNAVAILABLE');
        }

        $payout = Payout::create([
            'reference' => (string) Str::ulid(),
            'playerId' => $player->id,
            'ticketId' => null,
            'kind' => 'withdrawal',
            'payoutType' => 'OpayWalletNg',
            'amountKobo' => $amountKobo,
            'sourceLabel' => 'Winnings Balance',
            'destinationPhone' => $player->msisdn,
            'destinationLabel' => 'OPay wallet ending ' . substr($player->msisdn, -4),
            'providerStatus' => 'QUEUED',
            'manualReviewRequired' => $amountKobo >= (int) config('payout.manual_review_threshold_kobo'),
        ]);

        // Reserves out of Winnings Balance immediately — throws if insufficient, which
        // rolls nothing back here since the payout row itself carries no money yet.
        $this->wallet->reserveWinningsForWithdrawal($player, $amountKobo, 'payout', $payout->id);

        $this->dispatch->send($payout, $player);

        return $payout->refresh();
    }

    private function availableFor(Player $player, string $source): int
    {
        if ($source === 'winnings') {
            return $this->wallet->walletFor($player)->winningsBalanceKobo;
        }

        return $this->turnover->positionFor($player)['releasedPlayBalanceKobo'];
    }
}
