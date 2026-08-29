<?php

declare(strict_types=1);

namespace Betplus\Ussd;

/**
 * REQ-QA-013 / Story 8.7 — "each [screen] is asserted at 160 characters or fewer
 * including the prompt, and the build fails on overflow." The CON/END prefix Screen
 * adds counts too — a real aggregator counts the whole payload it receives.
 */
final class CharacterLimit
{
    public const MAX_CHARACTERS = 160;

    public static function fits(Screen $screen): bool
    {
        return mb_strlen($screen->render()) <= self::MAX_CHARACTERS;
    }
}
