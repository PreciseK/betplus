<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\Payout\Float\FloatService;
use App\Models\Payout;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * Story 4.1/4.4/4.8. Automatic-prize payouts never move the ledger here — the win was
 * already credited to Winnings Balance at settlement (Domain/Ticket/CreateTicket), and
 * REQ-PO-008/REQ-FLOAT-006 require that credit to survive a failed or halted dispatch
 * untouched. The confirmed-SUCCESS ledger movement (Winnings Balance -> OPAY_FLOAT) is
 * PollPayoutStatusJob's job, once OPay actually confirms the transfer.
 */
final class DispatchPayout
{
    public function __construct(
        private readonly OpayGateway $opay,
        private readonly FloatService $float,
    ) {
    }

    /** REQ-PO-010 — idempotent on (ticketId, payoutType); a retry returns the existing payout. */
    public function forWonTicket(Ticket $ticket): ?Payout
    {
        $existing = Payout::where('ticketId', $ticket->id)->where('payoutType', 'OpayWalletNg')->first();
        if ($existing !== null) {
            return $existing;
        }

        $outcome = $ticket->outcome;
        if ($outcome === null || !$outcome->won || $outcome->netCreditKobo <= 0) {
            return null;
        }

        $player = Player::findOrFail($ticket->playerId);

        $payout = Payout::create([
            'reference' => (string) Str::ulid(),
            'playerId' => $player->id,
            'ticketId' => $ticket->id,
            'kind' => 'automatic-prize',
            'payoutType' => 'OpayWalletNg',
            'amountKobo' => $outcome->netCreditKobo,
            'sourceLabel' => 'BlackRed net prize',
            'destinationPhone' => $player->msisdn,
            'destinationLabel' => 'OPay wallet ending ' . substr($player->msisdn, -4),
            'providerStatus' => 'QUEUED',
            'manualReviewRequired' => $outcome->netCreditKobo >= (int) config('payout.manual_review_threshold_kobo'),
        ]);

        $this->send($payout, $player);

        return $payout->refresh();
    }

    /** Sends (or re-sends, if still QUEUED after a halt) one payout to OPay. */
    public function send(Payout $payout, Player $player): void
    {
        if ($payout->manualReviewRequired) {
            // REQ-PO-007 — Betplus's own maker-checker gate, not OPay's. The approval UI
            // is Epic 6 back office; this build stops here and leaves it QUEUED.
            $payout->update(['providerStatus' => 'MANUAL_REVIEW']);

            return;
        }

        if ($this->float->isHalted()) {
            // REQ-FLOAT-006 — money already sits safely in the player's balance (or,
            // for a withdrawal, in PAYMENT_CLEARING pending refund); this just means no
            // OPay call is attempted yet. PollPayoutStatusJob's schedule picks it back
            // up once the float recovers.
            $payout->update(['providerStatus' => 'FLOAT_HALTED']);

            return;
        }

        $merchantOrderNo = $this->numericOrderNo($payout);
        $result = $this->opay->createPayout(
            $merchantOrderNo,
            $payout->amountKobo,
            $player->registeredName,
            $payout->destinationPhone,
            (string) config('opay.payout_notify_url'),
        );

        if ($result['status'] === 'error') {
            // Transport/provider failure at dispatch time — not a confirmed OPay status,
            // so it is NOT one of the seven known codes and must not be read as failure.
            $payout->update(['providerStatus' => 'DISPATCH_FAILED', 'dispatchedAt' => now()]);

            return;
        }

        $payout->update([
            'providerStatus' => $result['providerStatus'],
            'opayOrderNo' => $result['opayOrderNo'],
            'dispatchedAt' => now(),
        ]);
    }

    /**
     * OPay's merchantOrderNo must be digits only (see OpayGateway::createPayout's doc
     * comment) — derived from the payout's own id rather than its ULID reference, and
     * re-derivable identically for status queries later.
     */
    public function numericOrderNo(Payout $payout): string
    {
        return str_pad((string) $payout->id, 12, '0', STR_PAD_LEFT);
    }
}
