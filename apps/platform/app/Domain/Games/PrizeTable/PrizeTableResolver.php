<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Models\PrizeTable;
use Carbon\CarbonInterface;

/**
 * Resolution by (gameCode, stateCode, effectiveDate) per REQ-GEC-026. Accepts the
 * interface, not Illuminate\Support\Carbon specifically — a model's 'datetime' cast
 * can surface as either Carbon\Carbon or Illuminate\Support\Carbon depending on
 * context, and both implement CarbonInterface.
 */
final class PrizeTableResolver
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
            ->with('tiers')
            ->first();
    }
}
