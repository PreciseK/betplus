<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

use App\Models\CrashConfig;
use Carbon\CarbonInterface;

/** Mirrors PrizeTableResolver's resolution shape: latest published config effective by now. */
final class CrashConfigResolver
{
    public function resolveFor(string $gameCode, ?CarbonInterface $at = null): ?CrashConfig
    {
        $at ??= now();

        return CrashConfig::where('gameCode', $gameCode)
            ->where('status', 'published')
            ->where('effectiveAt', '<=', $at)
            ->orderByDesc('effectiveAt')
            ->first();
    }
}
