<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

/**
 * Value object representing a tier within the Heritage prize table.
 */
final readonly class HeritageTier
{
    public function __construct(
        public string $name,
        public int $probabilityBasisPoints,
        public int $multiplierHundredths,
        public string $outcomeType, // 'cash' | 'draw_entry' | 'none'
    ) {
    }
}
