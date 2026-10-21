<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

use InvalidArgumentException;

/**
 * Board generation and consistent-reveal winning-position assignment (REQ-HG-010, REQ-HG-011).
 */
final class HeritageBoard
{
    public const BOARD_SIZE = 9;
    public const PICK_SIZE = 5;

    /**
     * Tribe+leader -> the 5 catalogue numbers that belong to it (items 21-90).
     * @var array<string, list<int>>
     */
    public const TRIBE_LEADER_NUMBERS = [
        'yoruba:king' => [21, 22, 23, 24, 25],
        'yoruba:queen' => [26, 27, 28, 29, 30],
        'igbo:king' => [31, 32, 33, 34, 35],
        'igbo:queen' => [36, 37, 38, 39, 40],
        'hausa_fulani:king' => [41, 42, 43, 44, 45],
        'hausa_fulani:queen' => [46, 47, 48, 49, 50],
        'edo:king' => [51, 52, 53, 54, 55],
        'edo:queen' => [56, 57, 58, 59, 60],
        'efik_ibibio:king' => [61, 62, 63, 64, 65],
        'efik_ibibio:queen' => [66, 67, 68, 69, 70],
        'ijaw:king' => [71, 72, 73, 74, 75],
        'ijaw:queen' => [76, 77, 78, 79, 80],
        'middle_belt:king' => [81, 82, 83, 84, 85],
        'middle_belt:queen' => [86, 87, 88, 89, 90],
    ];

    /**
     * 9 distinct integers from 1-90, one per position, in position order.
     *
     * @param list<int>|null $winningPositions
     * @return list<int>
     */
    public static function drawBoard(
        string $seed,
        int $startCounter,
        ?array $winningPositions = null,
        ?string $tradition = null,
        ?string $leaderType = null
    ): array {
        $key = ($tradition ?? '') . ':' . ($leaderType ?? '');
        $authentic = self::TRIBE_LEADER_NUMBERS[$key] ?? null;

        if ($winningPositions === null || $authentic === null) {
            return HeritageSeed::drawWithoutReplacement($seed, $startCounter, range(1, 90), self::BOARD_SIZE);
        }

        $decoyPool = array_values(array_diff(range(1, 90), $authentic));
        $shuffledAuthentic = HeritageSeed::drawWithoutReplacement($seed, $startCounter, $authentic, count($authentic));
        $decoys = HeritageSeed::drawWithoutReplacement(
            $seed,
            $startCounter + count($authentic),
            $decoyPool,
            self::BOARD_SIZE - count($authentic)
        );

        $sortedWinners = array_values(array_unique($winningPositions));
        sort($sortedWinners);
        $nonWinningPositions = array_values(array_diff(range(0, 8), $sortedWinners));

        $board = array_fill(0, self::BOARD_SIZE, 0);
        foreach ($sortedWinners as $index => $position) {
            $board[$position] = $shuffledAuthentic[$index];
        }
        foreach ($nonWinningPositions as $index => $position) {
            $board[$position] = $decoys[$index];
        }

        return $board;
    }

    /**
     * Model 4 (pari-mutuel pool) — the one shared 5-of-9 winning combination every
     * pooled ticket in a draw window is compared against.
     *
     * @return list<int>
     */
    public static function drawPoolWinningPositions(string $seed, int $startCounter): array
    {
        $drawn = HeritageSeed::drawWithoutReplacement($seed, $startCounter, range(0, self::BOARD_SIZE - 1), self::PICK_SIZE);
        sort($drawn);

        return $drawn;
    }

    /**
     * Which 5 of the 9 positions are winners, constructed so that exactly
     * matchCount of them are inside selectedPositions (REQ-HG-011).
     *
     * @param list<int> $selectedPositions
     * @return list<int>
     */
    public static function assignWinningPositions(
        string $seed,
        int $startCounter,
        array $selectedPositions,
        int $matchCount
    ): array {
        if (count($selectedPositions) !== self::PICK_SIZE || count(array_unique($selectedPositions)) !== self::PICK_SIZE) {
            throw new InvalidArgumentException('selectedPositions must be exactly 5 distinct positions.');
        }
        foreach ($selectedPositions as $p) {
            if ($p < 0 || $p >= self::BOARD_SIZE) {
                throw new InvalidArgumentException('selectedPositions must be within 0-8.');
            }
        }

        $complement = array_values(array_diff(range(0, self::BOARD_SIZE - 1), $selectedPositions));
        $nonWinnerCount = self::PICK_SIZE - $matchCount;
        if ($nonWinnerCount > count($complement)) {
            throw new InvalidArgumentException("matchCount {$matchCount} is not achievable against a 5-of-9 pick.");
        }

        $winnersFromPick = HeritageSeed::drawWithoutReplacement($seed, $startCounter, $selectedPositions, $matchCount);
        $winnersFromRest = HeritageSeed::drawWithoutReplacement(
            $seed,
            $startCounter + $matchCount,
            $complement,
            $nonWinnerCount
        );

        $merged = array_merge($winnersFromPick, $winnersFromRest);
        sort($merged);

        return $merged;
    }
}
