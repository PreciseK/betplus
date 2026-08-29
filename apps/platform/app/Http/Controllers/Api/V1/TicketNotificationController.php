<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ticket\SendTicketReceiptSms;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

/**
 * Story 8.5 (REQ-USSD-005/REQ-NOT-008). Game-agnostic — ticket lookup by reference
 * needs no BlackRed/Heritage distinction, same as RevealTicket. apps/ussd calls this
 * explicitly after showing a result; web/app never call it (see
 * SendTicketReceiptSms's doc comment on why this isn't unconditional).
 */
class TicketNotificationController extends Controller
{
    public function __construct(private readonly SendTicketReceiptSms $receipt)
    {
    }

    /** POST /v1/tickets/{reference}/notify-sms */
    public function notifySms(string $reference): JsonResponse
    {
        $player = $this->player();
        $ticket = Ticket::where('reference', $reference)->where('playerId', $player->id)->first();
        if ($ticket === null) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        $this->receipt->send($player, $ticket);

        return response()->json(['status' => 'sent']);
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
