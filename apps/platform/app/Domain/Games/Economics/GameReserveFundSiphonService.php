<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Wallet\WalletService;
use App\Models\GameDailyLedger;
use App\Models\GameEconomicsConfig;
use Carbon\CarbonInterface;

/**
 * Daily job body for Model 1's reserve-fund siphon: for every game currently running
 * BALANCED_HYBRID with a reserveSiphonBps configured, move that % of the closed day's
 * net GGR (GameDailyLedger, already fed by every settlement path) into the segregated
 * RESERVE_FUND ledger account.
 */
final class GameReserveFundSiphonService
{
    public function __construct(
        private readonly EconomicsConfigResolver $configResolver,
        private readonly WalletService $wallet,
    ) {
    }

    /** @return int number of games siphoned */
    public function siphonDay(CarbonInterface $day): int
    {
        $siphoned = 0;

        // One config row per game can exist per day; distinct gameCode is the
        // candidate list, then EconomicsConfigResolver settles which one is actually
        // live for that game "as of" end of day.
        $gameCodes = GameEconomicsConfig::where('status', 'published')->distinct()->pluck('gameCode');

        foreach ($gameCodes as $gameCode) {
            $config = $this->configResolver->resolveFor($gameCode, $day->endOfDay());
            if ($config === null || $config->activeModel !== 'BALANCED_HYBRID') {
                continue;
            }

            $params = BalancedHybridParams::fromArray($config->paramsJson);
            if ($params->reserveSiphonBps <= 0) {
                continue;
            }

            $ledger = GameDailyLedger::where('gameCode', $gameCode)->where('ledgerDate', $day->toDateString())->first();
            $netGgrKobo = $ledger !== null ? $ledger->grossStakesKobo - $ledger->grossPrizesKobo : 0;
            if ($netGgrKobo <= 0) {
                continue;
            }

            $siphonKobo = intdiv($netGgrKobo * $params->reserveSiphonBps, 10_000);
            if ($siphonKobo <= 0) {
                continue;
            }

            $this->wallet->allocateReserveFund($siphonKobo, 'game_daily_ledger', $ledger->id);
            $siphoned++;
        }

        return $siphoned;
    }
}
