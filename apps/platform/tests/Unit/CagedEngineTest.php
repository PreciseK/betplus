<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\Caged\CagedEngine;
use App\Domain\Games\Engine\Caged\CagedTier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CagedEngineTest extends TestCase
{
    /** @return list<CagedTier> */
    private function tiers(): array
    {
        return [
            new CagedTier(1, 7180, 10_000, 125),
            new CagedTier(2, 4620, 10_000, 190),
            new CagedTier(3, 2310, 10_000, 380),
            new CagedTier(4, 1140, 10_000, 750),
            new CagedTier(5, 480, 10_000, 1800),
        ];
    }

    public function test_resolve_and_replay_are_byte_identical_for_the_same_inputs(): void
    {
        $engine = new CagedEngine();
        $seed = bin2hex(random_bytes(32));

        $resolved = $engine->resolve($seed, 2, 100_000, $this->tiers());
        $replayed = $engine->replay($seed, 2, 100_000, $this->tiers());

        $this->assertSame($resolved->escapedBirds, $replayed->escapedBirds);
        $this->assertSame($resolved->won, $replayed->won);
        $this->assertSame($resolved->grossPrizeKobo, $replayed->grossPrizeKobo);
        $this->assertSame($resolved->digest, $replayed->digest);
    }

    public function test_different_seeds_produce_different_outcomes_with_overwhelming_probability(): void
    {
        $engine = new CagedEngine();
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $engine->resolve(bin2hex(random_bytes(32)), 3, 100_000, $this->tiers())->escapedBirds;
        }

        $this->assertGreaterThan(1, count(array_unique($results)), 'Twenty random seeds produced an identical escaped-birds outcome every time.');
    }

    public function test_won_is_exactly_escaped_birds_meeting_or_exceeding_the_target(): void
    {
        $engine = new CagedEngine();
        for ($i = 0; $i < 100; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 3, 100_000, $this->tiers());
            $this->assertSame($result->escapedBirds >= 3, $result->won);
        }
    }

    public function test_gross_prize_is_stake_times_tier_multiplier_on_a_win(): void
    {
        $engine = new CagedEngine();
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, 1, 100_000, $this->tiers());
        } while (!$result->won);

        $this->assertSame(125_000, $result->grossPrizeKobo); // 100_000 * 1.25
    }

    public function test_a_loss_pays_nothing(): void
    {
        $engine = new CagedEngine();
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, 5, 100_000, $this->tiers());
        } while ($result->won);

        $this->assertSame(0, $result->grossPrizeKobo);
    }

    public function test_escaped_birds_is_always_between_zero_and_five(): void
    {
        $engine = new CagedEngine();
        for ($i = 0; $i < 200; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $this->tiers());
            $this->assertGreaterThanOrEqual(0, $result->escapedBirds);
            $this->assertLessThanOrEqual(5, $result->escapedBirds);
        }
    }

    public function test_the_escaped_birds_distribution_roughly_matches_the_configured_tier_probabilities(): void
    {
        $engine = new CagedEngine();
        $counts = array_fill(0, 6, 0);
        $samples = 4000;
        for ($i = 0; $i < $samples; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $this->tiers());
            $counts[$result->escapedBirds]++;
        }

        // Exact-count widths derived from the cumulative tier probabilities (design
        // spec §3): 28.20/25.60/23.10/11.70/6.60/4.80%.
        $expectedFractions = [0 => 0.2820, 1 => 0.2560, 2 => 0.2310, 3 => 0.1170, 4 => 0.0660, 5 => 0.0480];
        foreach ($expectedFractions as $escapedBirds => $expectedFraction) {
            $observedFraction = $counts[$escapedBirds] / $samples;
            $this->assertEqualsWithDelta(
                $expectedFraction,
                $observedFraction,
                0.04,
                "escapedBirds=$escapedBirds observed frequency $observedFraction too far from expected $expectedFraction",
            );
        }
    }

    public function test_rejects_a_target_below_one(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), 0, 100_000, $this->tiers());
    }

    public function test_rejects_a_target_above_five(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), 6, 100_000, $this->tiers());
    }

    public function test_rejects_tiers_with_fewer_than_five_entries(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing target 5');
        $incompleteTiers = [
            new CagedTier(1, 7180, 10_000, 125),
            new CagedTier(2, 4620, 10_000, 190),
            new CagedTier(3, 2310, 10_000, 380),
            new CagedTier(4, 1140, 10_000, 750),
            // Missing target 5
        ];
        $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $incompleteTiers);
    }

    public function test_rejects_tiers_with_duplicate_targets(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 5 entries');
        $tiersWithDuplicate = [
            new CagedTier(1, 7180, 10_000, 125),
            new CagedTier(2, 4620, 10_000, 190),
            new CagedTier(3, 2310, 10_000, 380),
            new CagedTier(4, 1140, 10_000, 750),
            new CagedTier(5, 480, 10_000, 1800),
            new CagedTier(1, 7180, 10_000, 125), // Duplicate
        ];
        $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $tiersWithDuplicate);
    }

    public function test_rejects_tiers_missing_a_target_in_the_middle(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing target 3');
        $tiersWithGap = [
            new CagedTier(1, 7180, 10_000, 125),
            new CagedTier(2, 4620, 10_000, 190),
            // Missing target 3
            new CagedTier(4, 1140, 10_000, 750),
            new CagedTier(5, 480, 10_000, 1800),
        ];
        $engine->resolve(bin2hex(random_bytes(32)), 4, 100_000, $tiersWithGap);
    }

    public function test_rejects_tiers_missing_target_one(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing target 1');
        $tiersWithoutFirst = [
            new CagedTier(2, 4620, 10_000, 190),
            new CagedTier(3, 2310, 10_000, 380),
            new CagedTier(4, 1140, 10_000, 750),
            new CagedTier(5, 480, 10_000, 1800),
        ];
        $engine->resolve(bin2hex(random_bytes(32)), 2, 100_000, $tiersWithoutFirst);
    }
}
