<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/** resolve/replay response shape, as a plain value object — mirrors BlackRed\EngineResult. */
final readonly class CagedEngineResult
{
    public function __construct(
        public int $escapedBirds,
        public int $targetBirds,
        public bool $won,
        public int $grossPrizeKobo,
        public string $digest,
        public string $engineVersion,
    ) {
    }
}
