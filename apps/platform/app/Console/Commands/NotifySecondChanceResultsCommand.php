<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Draw\DrawPartnerAdapter;
use App\Domain\Notifications\SmsSender;
use App\Models\HeritageSecondChanceEntry;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Story 7.9 (REQ-HG-038) — "every second-chance player receives a result
 * notification whether they won or not." Polls the partner for confirmed entries
 * that haven't been notified yet; entries the partner hasn't resulted are left for
 * the next run, not treated as a failure.
 */
class NotifySecondChanceResultsCommand extends Command
{
    protected $signature = 'heritage:notify-second-chance-results';
    protected $description = 'Story 7.9 — notify every resolved second-chance entrant of their draw result (REQ-HG-038)';

    public function handle(DrawPartnerAdapter $partner, SmsSender $sms): int
    {
        $entries = HeritageSecondChanceEntry::where('status', 'confirmed')
            ->whereNull('resultNotifiedAt')
            ->whereNotNull('partnerReference')
            ->get();

        $notified = 0;
        foreach ($entries as $entry) {
            $result = $partner->getResults($entry->partnerReference);
            if ($result === null) {
                continue; // not yet resulted by the partner
            }

            $player = Player::find($entry->playerId);
            $ticket = Ticket::find($entry->ticketId);
            if ($player !== null && $ticket !== null) {
                $sms->send($player->msisdn, 'heritage_second_chance_result.v1', [
                    'draw_name' => $entry->partnerCode ?? '5/90 draw',
                    'outcome' => $result['won'] ? ($result['prizeDescription'] ?? 'a win') : 'no prize this time',
                    'ticket_ref' => $ticket->reference,
                ], $player->id);
            }

            $entry->update(['resultJson' => $result, 'resultNotifiedAt' => now()]);
            $notified++;
        }

        $this->info("Notified {$notified} second-chance entrant(s) of their result.");

        return self::SUCCESS;
    }
}
