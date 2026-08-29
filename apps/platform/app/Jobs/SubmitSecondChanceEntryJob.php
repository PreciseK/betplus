<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Games\Draw\DrawPartnerAdapter;
use App\Domain\Notifications\SmsSender;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\HeritageDrawCalendarEntry;
use App\Models\HeritageSecondChanceEntry;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Story 7.7/7.8 — REQ-HG-034: queued off the settlement path, never blocking it.
 * Retries against the next open draw on the calendar; after MAX_ROLL_ATTEMPTS
 * (interpreting REQ-HG-037's "within 3 draws" as 3 submission attempts, since a real
 * draw-by-draw calendar wait is calendar-integration work this pass doesn't build)
 * the entry is compensated rather than left pending forever.
 */
class SubmitSecondChanceEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ROLL_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(public readonly int $ticketId)
    {
    }

    public function handle(DrawPartnerAdapter $partner, WalletService $wallet, SmsSender $sms): void
    {
        $entry = HeritageSecondChanceEntry::where('ticketId', $this->ticketId)->first();
        if ($entry === null || in_array($entry->status, ['confirmed', 'credited'], true)) {
            return;
        }

        $draw = HeritageDrawCalendarEntry::where('status', 'open')
            ->where('cutoffAt', '>', now())
            ->orderBy('scheduledAt')
            ->first();

        if ($draw === null) {
            $this->rollOrCompensate($entry, $wallet, $sms);

            return;
        }

        $result = $partner->submitEntry((string) $draw->id, $entry->selectedNumbers);

        if (!$result->success || $result->partnerReference === null) {
            // REQ-HG-035 — never presented as lodged without a verifiable reference.
            $this->rollOrCompensate($entry, $wallet, $sms);

            return;
        }

        $entry->update([
            'status' => 'confirmed',
            'partnerCode' => $draw->partnerCode,
            'partnerReference' => $result->partnerReference,
            'drawCalendarEntryId' => $draw->id,
            'submittedAt' => now(),
            'confirmedAt' => now(),
        ]);

        $player = Player::find($entry->playerId);
        $ticket = Ticket::find($this->ticketId);
        if ($player !== null && $ticket !== null) {
            $sms->send($player->msisdn, 'heritage_second_chance.v1', [
                'numbers' => implode(', ', $entry->selectedNumbers),
                'draw_name' => $draw->drawName,
                'draw_time' => $draw->scheduledAt->format('D, M j g:ia'),
                'partner_ref' => $result->partnerReference,
                'ticket_ref' => $ticket->reference,
            ], $player->id);
        }
    }

    private function rollOrCompensate(HeritageSecondChanceEntry $entry, WalletService $wallet, SmsSender $sms): void
    {
        $entry->increment('rollCount');

        if ($entry->rollCount < self::MAX_ROLL_ATTEMPTS) {
            $entry->update(['status' => 'rolled']);
            self::dispatch($this->ticketId)->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));

            return;
        }

        $player = Player::find($entry->playerId);
        $ticket = Ticket::find($this->ticketId);
        if ($player !== null) {
            $wallet->compensateFailedSecondChanceEntry($player, $entry->entryStakeKobo, 'heritageSecondChanceEntry', $entry->id);
        }
        $entry->update(['status' => 'credited']);

        AuditLog::create([
            'actorType' => 'system',
            'action' => 'heritage.second_chance.compensated',
            'targetTable' => 'heritageSecondChanceEntry',
            'targetId' => $entry->id,
            'reason' => 'Could not be lodged with any contracted draw partner within ' . self::MAX_ROLL_ATTEMPTS . ' attempts (REQ-HG-037).',
        ]);

        if ($player !== null && $ticket !== null) {
            $sms->send($player->msisdn, 'heritage_second_chance_compensated.v1', [
                'amount' => number_format($entry->entryStakeKobo / 100, 2),
                'ticket_ref' => $ticket->reference,
            ], $player->id);
        }
    }
}
