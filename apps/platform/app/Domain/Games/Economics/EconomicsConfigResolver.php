<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\GameEconomicsConfig;
use Carbon\CarbonInterface;

/** Mirrors CrashConfigResolver/PrizeTableResolver's resolution shape exactly: latest published config effective by now. */
final class EconomicsConfigResolver
{
    public function resolveFor(string $gameCode, ?CarbonInterface $at = null): ?GameEconomicsConfig
    {
        $at ??= now();

        return GameEconomicsConfig::where('gameCode', $gameCode)
            ->where('status', 'published')
            ->where('effectiveAt', '<=', $at)
            ->orderByDesc('effectiveAt')
            ->first();
    }
}
