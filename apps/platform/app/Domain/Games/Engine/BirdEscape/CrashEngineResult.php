<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BirdEscape;

/** resolve/replay response shape, as a plain value object — mirrors BlackRed's EngineResult. */
final readonly class CrashEngineResult
{
    public function __construct(
        public int $crashMultiplierHundredths,
        public string $digest,
        public string $engineVersion,
    ) {
    }
}
