<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/**
 * Plain input to the engine — deliberately not an Eloquent model (REQ-GEC-002),
 * mirroring BlackRed\EngineTier. probabilityNumerator/Denominator is the
 * CUMULATIVE win probability P(escapedBirds >= targetBirds), not an exact-count
 * width — see the design spec §3 for why, and CagedEngine::escapedBirdsFor()
 * for how the engine derives its internal buckets from these five values.
 */
final readonly class CagedTier
{
    public function __construct(
        public int $targetBirds,
        public int $probabilityNumerator,
        public int $probabilityDenominator,
        public int $multiplierHundredths,
    ) {
    }
}
