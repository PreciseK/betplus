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
            ->with('outcome')
            ->first();

        if ($ticket === null || $ticket->outcome === null) {
            return null;
        }

        if ($ticket->revealedAt === null) {
            $ticket->update(['revealedAt' => now(), 'status' => 'REVEALED']);
        }

        return $ticket;
    }
}
