<?php

declare(strict_types=1);

namespace Betplus\EngineBlackred;

/**
 * BlackRed engine entry point. Pure function of (seed, ticketId, input) — no state,
 * no external calls, no randomness (REQ-GEC-001..003). Scaffolding only; resolve(),
 * replay() and describe() are implemented in the story that ports the game logic.
 */
final class Engine
{
    /** @return array<string, string> */
    public function describe(): array
    {
        return [
            'engine' => 'blackred',
            'version' => '0.0.0-scaffold',
        ];
    }
}
