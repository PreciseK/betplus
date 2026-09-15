<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\GameDailyLedger;
use Illuminate\Database\QueryException;

/**
 * Live per-game daily P&L, fed by every settlement path (ticket games and the
 * crash engine alike) so Model 3 (Daily Loss-Stop) can check "how much has this
 * game lost today" without re-summing Ticket/CrashBet rows on every bet.
 */
class GameDailyLedgerService
{
    public function recordSettlement(string $gameCode, int $stakeKobo, int $prizeKobo): void
    {
        $today = now()->toDateString();

        try {
            GameDailyLedger::firstOrCreate(
                ['gameCode' => $gameCode, 'ledgerDate' => $today],
                ['grossStakesKobo' => 0, 'grossPrizesKobo' => 0],
            );
        } catch (QueryException) {
            // gameDailyLedger's only unique index is (gameCode, ledgerDate), so any
            // insert failure here is that race — another concurrent settlement
            // created today's row first (same pattern as RoundLifecycleService's
            // retry-on-unique-violation). The increments below still apply.
        }

        GameDailyLedger::where('gameCode', $gameCode)->where('ledgerDate', $today)->increment('grossStakesKobo', $stakeKobo);
        GameDailyLedger::where('gameCode', $gameCode)->where('ledgerDate', $today)->increment('grossPrizesKobo', $prizeKobo);
    }

    /** Net GGR (stakes - prizes) for gameCode so far today, in kobo. Negative means the game is net-down for the day. */
    public function netGgrTodayKobo(string $gameCode): int
    {
        $row = GameDailyLedger::where('gameCode', $gameCode)->where('ledgerDate', now()->toDateString())->first();

        if ($row === null) {
            return 0;
        }

        return $row->grossStakesKobo - $row->grossPrizesKobo;
    }
}
