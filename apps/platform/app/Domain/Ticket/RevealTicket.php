<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Models\Player;
use App\Models\Ticket;

/**
 * REQ-TKT-004 — "the reveal is presentation." Settlement already happened
 * synchronously at creation (see CreateTicket); this only discloses it and stamps
 * revealedAt the first time it's called. No money moves here.
 */
final class RevealTicket
{
    public function reveal(Player $player, string $reference): ?Ticket
    {
        $ticket = Ticket::where('reference', $reference)
            ->where('playerId', $player->id)
            ->with('outcome', 'poolEntry.poolDraw')
            ->first();

        if ($ticket === null) {
            return null;
        }

        // Model 4 — the ticket exists and is real, it just has no outcome yet
        // because its pool hasn't drawn. The controller shapes this differently
        // from "not found"; it never falls through to the outcome check below.
        if ($ticket->status === 'PENDING_DRAW') {
            return $ticket;
        }

        if ($ticket->outcome === null) {
            return null;
        }

        if ($ticket->revealedAt === null) {
            $ticket->update(['revealedAt' => now(), 'status' => 'REVEALED']);
        }

        return $ticket;
    }
}
