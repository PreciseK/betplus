<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BlackRed;

/** §6.2 resolve/replay response shape, as a plain value object. */
final readonly class EngineResult
{
    /**
     * @param list<'B'|'R'> $result
     */
    public function __construct(
        public array $result,
        public bool $won,
        public int $grossPrizeKobo,
        public string $digest,
        public string $engineVersion,
    ) {
    }
}
