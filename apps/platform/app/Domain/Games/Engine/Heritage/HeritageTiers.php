<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

use InvalidArgumentException;

/**
 * Heritage's outcome tiers (PRD §9.4) and the structural match-count each implies.
 */
final class HeritageTiers
{
    /** @var array<string, list<int>> */
    public const TIER_MATCH_COUNTS = [
        'TIER_JACKPOT' => [5],
        'TIER_HIGH' => [4],
        'TIER_LOSS' => [1, 2, 3],
    ];

    /**
     * @param list<HeritageTier> $tiers
     */
    public static function validatePrizeTable(array $tiers): void
    {
        $names = array_map(fn (HeritageTier $t) => $t->name, $tiers);
        $expectedNames = array_keys(self::TIER_MATCH_COUNTS);
        sort($expectedNames);
        $sortedActualNames = $names;
        sort($sortedActualNames);

        if ($sortedActualNames !== $expectedNames) {
            throw new InvalidArgumentException(
                'Prize table must contain exactly the tiers ' . implode(', ', $expectedNames)
                . ', got ' . implode(', ', $sortedActualNames) . '.'
            );
        }

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Prize table has a duplicate tier name.');
        }

        $totalBp = array_sum(array_map(fn (HeritageTier $t) => $t->probabilityBasisPoints, $tiers));
        if ($totalBp !== 10000) {
            throw new InvalidArgumentException(
                "Tier probabilities must sum to exactly 10000 basis points, got {$totalBp}."
            );
        }
    }

    /**
     * @param list<HeritageTier> $tiers
     */
    public static function drawTier(string $seed, int $counter, array $tiers): HeritageTier
    {
        $totalBp = array_sum(array_map(fn (HeritageTier $t) => $t->probabilityBasisPoints, $tiers));
        if ($totalBp <= 0) {
            $totalBp = 10000;
        }

        $roll = HeritageSeed::chooseIndex($seed, $counter, $totalBp);
        $cumulative = 0;
        foreach ($tiers as $tier) {
            $cumulative += $tier->probabilityBasisPoints;
            if ($roll < $cumulative) {
                return $tier;
            }
        }

        return $tiers[array_key_last($tiers)];
    }

    public static function drawMatchCount(string $seed, int $counter, string $tierName): int
    {
        $options = self::TIER_MATCH_COUNTS[$tierName] ?? [1, 2, 3];
        if (count($options) === 1) {
            return $options[0];
        }

        $idx = HeritageSeed::chooseIndex($seed, $counter, count($options));

        return $options[$idx];
    }
}
