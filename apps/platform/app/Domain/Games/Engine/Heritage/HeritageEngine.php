<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

use InvalidArgumentException;

/**
 * §9.3/§6.2 — The entire Heritage game engine in pure PHP.
 * Pure function of (seed, stake, prize_table, player_input):
 * no I/O, no database, no internally generated randomness (REQ-GEC-001..003).
 */
final class HeritageEngine
{
    public const VERSION = 'heritage-1.0.0';

    private const TIER_COUNTER = 0;
    private const MATCH_COUNTER = 1;
    private const BOARD_COUNTER_START = 100;
    private const WINNER_COUNTER_START = 200;
    private const POOL_COUNTER_START = 300;

    public const SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS = 1000;

    /**
     * @param list<HeritageTier> $tiers
     * @param list<int> $selectedPositions
     */
    public function resolve(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        array $tiers,
        array $selectedPositions,
        string $tradition,
        string $leaderType
    ): HeritageEngineResult {
        usort($tiers, fn (HeritageTier $a, HeritageTier $b) => strcmp($a->name, $b->name));
        HeritageTiers::validatePrizeTable($tiers);

        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('Invalid hex seed provided.');
        }

        $tier = HeritageTiers::drawTier($seed, self::TIER_COUNTER, $tiers);
        $matchCount = HeritageTiers::drawMatchCount($seed, self::MATCH_COUNTER, $tier->name);

        $winningPositions = HeritageBoard::assignWinningPositions(
            $seed,
            self::WINNER_COUNTER_START,
            $selectedPositions,
            $matchCount
        );

        $board = HeritageBoard::drawBoard(
            $seed,
            self::BOARD_COUNTER_START,
            $winningPositions,
            $tradition,
            $leaderType
        );

        $grossPrizeKobo = 0;
        $secondChanceStakeKobo = null;
        if ($tier->outcomeType === 'cash') {
            $grossPrizeKobo = intdiv($stakeKobo * $tier->multiplierHundredths, 100);
        } elseif ($tier->outcomeType === 'draw_entry') {
            $secondChanceStakeKobo = intdiv($stakeKobo * self::SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS, 10000);
        }

        $digest = HeritageDigest::of($seedHex, $selectedPositions, $board, $winningPositions, $tier->name);

        return new HeritageEngineResult(
            outcomeTier: $tier->name,
            grossPrizeKobo: $grossPrizeKobo,
            board: $board,
            winningPositions: $winningPositions,
            selectedPositions: $selectedPositions,
            matchCount: $matchCount,
            tradition: $tradition,
            leaderType: $leaderType,
            secondChanceStakeKobo: $secondChanceStakeKobo,
            engineVersion: self::VERSION,
            digest: $digest,
        );
    }

    /**
     * Replay performs identical computation for audit and verifiability (REQ-GEC-001).
     *
     * @param list<HeritageTier> $tiers
     * @param list<int> $selectedPositions
     */
    public function replay(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        array $tiers,
        array $selectedPositions,
        string $tradition,
        string $leaderType
    ): HeritageEngineResult {
        return $this->resolve(
            $ticketId,
            $seedHex,
            $stakeKobo,
            $tiers,
            $selectedPositions,
            $tradition,
            $leaderType
        );
    }

    /**
     * Model 4 (pari-mutuel pool) — draw 5 winning positions for the pool window.
     *
     * @return list<int>
     */
    public function drawPoolOutcome(string $seedHex): array
    {
        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('Invalid hex seed provided.');
        }

        return HeritageBoard::drawPoolWinningPositions($seed, self::POOL_COUNTER_START);
    }

    /**
     * Engine description contract.
     *
     * @return array{engine: string, engine_version: string, game_code: string, board_size: int, pick_size: int, tier_names: list<string>}
     */
    public function describe(): array
    {
        $names = array_keys(HeritageTiers::TIER_MATCH_COUNTS);
        sort($names);

        return [
            'engine' => 'heritage',
            'engine_version' => self::VERSION,
            'game_code' => 'HERITAGE',
            'board_size' => HeritageBoard::BOARD_SIZE,
            'pick_size' => HeritageBoard::PICK_SIZE,
            'tier_names' => $names,
        ];
    }
}
