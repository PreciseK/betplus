<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\CrashRound;

/**
 * What an EconomicsModelStrategy needs beyond gameCode/stakeKobo, carrying the one
 * thing that differs by engine shape: the crash engine's strategies need the live
 * round (to read/grow its exposureKobo); the three ticket engines don't have a round
 * at all.
 */
final class EconomicsContext
{
    private function __construct(public readonly ?CrashRound $crashRound)
    {
    }

    public static function forTicket(): self
    {
        return new self(null);
    }

    public static function forCrashRound(CrashRound $round): self
    {
        return new self($round);
    }
}
