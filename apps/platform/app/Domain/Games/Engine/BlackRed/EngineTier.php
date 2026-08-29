<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BlackRed;

/** Plain input to the engine — deliberately not an Eloquent model (REQ-GEC-002). */
final readonly class EngineTier
{
    public function __construct(
        public int $positions,
        public int $multiplierHundredths,
    ) {
    }
}
