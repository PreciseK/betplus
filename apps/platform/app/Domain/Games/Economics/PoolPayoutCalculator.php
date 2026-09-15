<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * The one piece of math every Model 4 settlement path shares: split a net pool
 * (already net of rake) among winning entries proportional to stake, truncated to
 * the kobo. Used once per pool for BlackRed/Caged (no tiers) and once per tier for
 * Heritage.
 */
final class PoolPayoutCalculator
{
    /**
     * @param array<int, int> $winnerStakesByEntryId
     * @return array{payouts: array<int, int>, remainderKobo: int} payouts keyed by
     *   the same entry id; remainderKobo is whatever truncation left over, to route
     *   to HOUSE_REVENUE alongside the rake so the ledger always balances exactly.
     */
    public function split(int $netPoolKobo, array $winnerStakesByEntryId): array
    {
        if ($winnerStakesByEntryId === [] || $netPoolKobo <= 0) {
            return ['payouts' => [], 'remainderKobo' => $netPoolKobo];
        }

        $totalWinningStakeKobo = array_sum($winnerStakesByEntryId);
        $payouts = [];
        $distributed = 0;

        foreach ($winnerStakesByEntryId as $entryId => $stakeKobo) {
            $payoutKobo = intdiv($netPoolKobo * $stakeKobo, $totalWinningStakeKobo);
            $payouts[$entryId] = $payoutKobo;
            $distributed += $payoutKobo;
        }

        return ['payouts' => $payouts, 'remainderKobo' => $netPoolKobo - $distributed];
    }
}
