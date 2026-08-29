<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use Carbon\CarbonInterface;

/**
 * Story 5.4 (REQ-RG-007) — net position = total won minus total staked, across every
 * game, over rolling 7/30/90-day windows. Winnings credited (PLAYER_WINNINGS credit
 * entries from ticket settlement) minus stakes placed (PLAYER_PLAY debit entries with
 * referenceType='ticket') — outcome-neutral by construction, matching REQ-RG-006's
 * "the player need not win; outcome is irrelevant" framing for turnover, and the same
 * arithmetic the reality-check display needs for net position.
 */
final class NetPositionService
{
    /** @return array{sevenDays:int, thirtyDays:int, ninetyDays:int} */
    public function positionsFor(Player $player): array
    {
        return [
            'sevenDays' => $this->netSince($player, now()->subDays(7)),
            'thirtyDays' => $this->netSince($player, now()->subDays(30)),
            'ninetyDays' => $this->netSince($player, now()->subDays(90)),
        ];
    }

    private function netSince(Player $player, CarbonInterface $since): int
    {
        $stakeAccount = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        $winningsAccount = LedgerAccount::where('type', 'PLAYER_WINNINGS')->where('scope', (string) $player->id)->first();

        $staked = $stakeAccount === null ? 0 : (int) LedgerEntry::where('accountId', $stakeAccount->id)
            ->where('direction', 'debit')->where('referenceType', 'ticket')
            ->where('createdAt', '>=', $since)->sum('amountKobo');

        $won = $winningsAccount === null ? 0 : (int) LedgerEntry::where('accountId', $winningsAccount->id)
            ->where('direction', 'credit')->where('referenceType', 'ticket')
            ->where('createdAt', '>=', $since)->sum('amountKobo');

        return $won - $staked;
    }
}
