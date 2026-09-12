<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BirdEscape;

/**
 * The public commitment. Computed once at round creation and published to players
 * immediately (before the crash multiplier itself is revealed) so a round's fairness
 * is checkable after the fact: reveal seedHex + crashMultiplierHundredths, anyone
 * recomputes this digest and BirdEscapeEngine::replay() to verify both — mirrors
 * BlackRed's Digest::of() / TicketAuditController::replay.
 */
final class Digest
{
    public static function of(string $seedHex, int $roundNumber, int $crashMultiplierHundredths): string
    {
        return hash('sha256', $seedHex . '|' . $roundNumber . '|' . $crashMultiplierHundredths);
    }
}
