<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Wallet\WalletService;
use App\Models\Payout;
use App\Models\Player;

/**
 * Applies a provider status observed from either the payout callback or a status poll.
 * The ledger movement only happens here, on a CONFIRMED terminal status — never at
 * dispatch time — so REQ-PO-008/REQ-FLOAT-006 hold structurally: an automatic prize
 * that never confirms simply never leaves Winnings Balance.
 */
final class SettlePayoutStatus
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PayoutStatusMapper $mapper,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    public function apply(Payout $payout, string $providerStatus): void
    {
        if ($payout->confirmedAt !== null) {
            return; // Already settled — a replayed callback or poll must not double-post.
        }

        if (!$this->mapper->isKnown($providerStatus)) {
            // REQ-PO-006 — never read as failure; escalate instead.
            $payout->update(['providerStatus' => $providerStatus, 'manualReviewRequired' => true]);

            return;
        }

        if ($this->mapper->isTerminalSuccess($providerStatus)) {
            $player = Player::findOrFail($payout->playerId);
            if ($payout->kind === 'withdrawal') {
                $this->wallet->confirmWithdrawalPayout($payout->amountKobo, 'payout', $payout->id);
            } else {
                $this->wallet->confirmPrizePayout($player, $payout->amountKobo, 'payout', $payout->id);
            }
            $payout->update(['providerStatus' => $providerStatus, 'confirmedAt' => now()]);

            // Story 6.10 — payout funnel step (REQ-ANL-007).
            $this->analytics->record('payout_completed', $player, 'web', properties: ['amount_kobo' => $payout->amountKobo, 'kind' => $payout->kind]);

            return;
        }

        if ($this->mapper->isTerminalFailure($providerStatus)) {
            if ($payout->kind === 'withdrawal') {
                $player = Player::findOrFail($payout->playerId);
                $this->wallet->refundFailedWithdrawal($player, $payout->amountKobo, 'payout', $payout->id);
            }
            // automatic-prize: no ledger movement — the credit from settlement stands.
            $payout->update(['providerStatus' => $providerStatus, 'confirmedAt' => now()]);

            return;
        }

        // Still in flight (PENDING/CHECKING) — record the latest known status only.
        $payout->update(['providerStatus' => $providerStatus]);
    }
}
