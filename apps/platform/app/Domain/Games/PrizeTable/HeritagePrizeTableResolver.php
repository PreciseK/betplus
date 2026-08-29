<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Models\PrizeTable;
use Carbon\CarbonInterface;

/**
 * Heritage's counterpart to PrizeTableResolver — same (gameCode, stateCode,
 * effectiveDate) resolution (REQ-GEC-026) against the same prizeTable table, eager
 * loading heritageTiers instead of the BlackRed-shaped tiers relation.
 */
final class HeritagePrizeTableResolver
{
    public function resolveFor(string $gameCode, string $stateCode, ?CarbonInterface $at = null): ?PrizeTable
    {
        $at ??= now();

        return $this->query($gameCode, $stateCode, $at)
            ?? $this->query($gameCode, null, $at);
    }

    private function query(string $gameCode, ?string $stateCode, CarbonInterface $at): ?PrizeTable
    {
        return PrizeTable::where('gameCode', $gameCode)
            ->where('stateCode', $stateCode)
            ->where('status', 'published')
            ->where('effectiveAt', '<=', $at)
            ->orderByDesc('effectiveAt')
            ->with('heritageTiers')
            ->first();
    }
}
