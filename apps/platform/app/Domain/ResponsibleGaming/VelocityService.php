<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\VelocityFlag;

/**
 * Story 5.7 (REQ-RG-020/021) — surfaces behaviour to a review queue; never blocks
 * play itself (that's what limits/cool-off/self-exclusion/registry are for). BlackRed
 * gets a stricter rapid-escalation threshold than the platform default because it's
 * fast-cycle and repeatable with no natural pause (REQ-RG-021) — Heritage isn't built
 * yet, so only BlackRed's threshold exists to configure.
 *
 * Only rapid stake escalation and late-night velocity are implemented — sustained
 * low-variance play and deposit-decline chasing need pattern history this build
 * doesn't compute; not fabricated.
 */
final class VelocityService
{
    private const BLACKRED_ESCALATION_MULTIPLE = 3; // a stake 3x the player's own recent average, flagged
    private const RECENT_TICKET_SAMPLE = 5;
    private const LATE_NIGHT_START_HOUR = 1; // 01:00–05:00 WAT
    private const LATE_NIGHT_END_HOUR = 5;
    private const LATE_NIGHT_TICKET_THRESHOLD = 10;

    /** Called after a ticket commits — never in the eligibility path, so it can't block or slow play. */
    public function evaluateAfterTicket(Player $player, string $gameCode, int $stakeKobo): void
    {
        $this->checkRapidEscalation($player, $gameCode, $stakeKobo);
        $this->checkLateNightVelocity($player, $gameCode);
    }

    private function checkRapidEscalation(Player $player, string $gameCode, int $stakeKobo): void
    {
        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        if ($account === null) {
            return;
        }

        $recentStakes = LedgerEntry::where('accountId', $account->id)
            ->where('direction', 'debit')->where('referenceType', 'ticket')
            ->orderByDesc('id')->limit(self::RECENT_TICKET_SAMPLE + 1)->pluck('amountKobo');

        // Exclude the ticket just placed (the first row) from its own baseline.
        $priorStakes = $recentStakes->slice(1);
        if ($priorStakes->count() < self::RECENT_TICKET_SAMPLE) {
            return; // not enough history yet to call anything "rapid"
        }

        $average = (int) $priorStakes->avg();
        if ($average > 0 && $stakeKobo >= $average * self::BLACKRED_ESCALATION_MULTIPLE) {
            $multiple = self::BLACKRED_ESCALATION_MULTIPLE;
            $sample = self::RECENT_TICKET_SAMPLE;
            VelocityFlag::create([
                'playerId' => $player->id,
                'gameCode' => $gameCode,
                'flagType' => 'rapid_stake_escalation',
                'detail' => "Stake {$stakeKobo}kobo is >= {$multiple}x the {$sample}-ticket average of {$average}kobo.",
            ]);
        }
    }

    private function checkLateNightVelocity(Player $player, string $gameCode): void
    {
        $hour = (int) now()->setTimezone('Africa/Lagos')->format('G');
        if ($hour < self::LATE_NIGHT_START_HOUR || $hour > self::LATE_NIGHT_END_HOUR) {
            return;
        }

        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        if ($account === null) {
            return;
        }

        $windowStart = now()->setTimezone('Africa/Lagos')->startOfDay()->addHours(self::LATE_NIGHT_START_HOUR);
        $ticketsTonight = LedgerEntry::where('accountId', $account->id)
            ->where('direction', 'debit')->where('referenceType', 'ticket')
            ->where('createdAt', '>=', $windowStart)->count();

        if ($ticketsTonight === self::LATE_NIGHT_TICKET_THRESHOLD) {
            // Fires once per session, at the threshold, not on every ticket after it.
            VelocityFlag::create([
                'playerId' => $player->id,
                'gameCode' => $gameCode,
                'flagType' => 'late_night_velocity',
                'detail' => "{$ticketsTonight} tickets placed between 01:00-05:00 WAT.",
            ]);
        }
    }
}
