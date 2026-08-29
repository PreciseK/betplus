<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Notifications\SmsSender;
use App\Models\Player;
use App\Models\Ticket;

/**
 * Story 8.5 / REQ-USSD-005, REQ-NOT-008 — "any USSD outcome is mirrored to SMS,
 * because a USSD screen is transient." Settlement already happened synchronously at
 * ticket creation (see CreateTicket/CreateHeritageTicket's own doc comments) — this
 * only sends the receipt, so a dropped session never costs the player their result.
 *
 * Deliberately triggered by the caller (apps/ussd's MenuEngine, after purchase),
 * not unconditionally inside CreateTicket/CreateHeritageTicket: `ticket` carries no
 * channel column, and sending an SMS receipt on every web/app ticket too would be
 * unrequested noise for channels with a persistent UI that already shows the result.
 */
final class SendTicketReceiptSms
{
    public function __construct(private readonly SmsSender $sms)
    {
    }

    public function send(Player $player, Ticket $ticket): void
    {
        $outcome = $ticket->outcome;
        if ($outcome === null) {
            return;
        }

        $result = $outcome->won
            ? 'You won NGN ' . number_format($outcome->netCreditKobo / 100, 2)
            : 'No win this time';

        $this->sms->send($player->msisdn, 'ticket_receipt.v1', [
            'game' => $ticket->gameCode,
            'reference' => $ticket->reference,
            'result' => $result,
        ], $player->id);
    }
}
