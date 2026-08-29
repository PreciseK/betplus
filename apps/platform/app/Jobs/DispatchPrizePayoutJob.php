<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payout\DispatchPayout;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Story 4.1 / D-09 — payout dispatch is on the async boundary, off the play path.
 * CreateTicket queues this immediately after a winning settlement; it creates the
 * payout row (idempotent on ticketId+payoutType) and makes the first OPay attempt,
 * then hands off to PollPayoutStatusJob for confirmation.
 */
class DispatchPrizePayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $ticketId)
    {
    }

    public function handle(DispatchPayout $dispatch): void
    {
        $ticket = Ticket::with('outcome')->find($this->ticketId);
        if ($ticket === null) {
            return;
        }

        $payout = $dispatch->forWonTicket($ticket);
        if ($payout !== null) {
            PollPayoutStatusJob::dispatch($payout->id)->delay(now()->addSeconds(30));
        }
    }
}
