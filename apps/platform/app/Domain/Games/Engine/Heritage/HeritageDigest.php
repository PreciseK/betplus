<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

/**
 * PRD §6.2 — sha256 of the canonical outcome, returned alongside every resolve/replay.
 */
final class HeritageDigest
{
    /**
     * @param list<int> $selectedPositions
     * @param list<int> $board
     * @param list<int> $winningPositions
     */
    public static function of(
        string $seedHex,
        array $selectedPositions,
        array $board,
        array $winningPositions,
        string $outcomeTier
    ): string {
        $parts = [
            $seedHex,
            implode(',', $selectedPositions),
            implode(',', $board),
            implode(',', $winningPositions),
            $outcomeTier,
        ];

        return hash('sha256', implode('|', $parts));
    }
}
