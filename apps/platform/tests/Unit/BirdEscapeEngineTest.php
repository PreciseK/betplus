<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BirdEscapeEngineTest extends TestCase
{
    public function test_resolve_and_replay_are_byte_identical_for_the_same_inputs(): void
    {
        $engine = new BirdEscapeEngine();
        $seed = bin2hex(random_bytes(32));

        $resolved = $engine->resolve($seed, 1, 500);
        $replayed = $engine->replay($seed, 1, 500);

        $this->assertSame($resolved->crashMultiplierHundredths, $replayed->crashMultiplierHundredths);
        $this->assertSame($resolved->digest, $replayed->digest);
    }

    public function test_different_round_numbers_produce_different_crash_points_with_overwhelming_probability(): void
    {
        $engine = new BirdEscapeEngine();
        $seed = bin2hex(random_bytes(32));

        $results = [];
        for ($round = 1; $round <= 20; $round++) {
            $results[] = $engine->resolve($seed, $round, 500)->crashMultiplierHundredths;
        }

        $this->assertGreaterThan(1, count(array_unique($results)), 'Twenty round numbers against the same seed produced an identical crash point every time.');
    }

    public function test_crash_multiplier_is_always_within_the_valid_range(): void
    {
        $engine = new BirdEscapeEngine();

        for ($round = 1; $round <= 100; $round++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), $round, 500);
            $this->assertGreaterThanOrEqual(100, $result->crashMultiplierHundredths);
            $this->assertLessThanOrEqual(BirdEscapeEngine::ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS, $result->crashMultiplierHundredths);
        }
    }

    public function test_multiplier_strictly_bounded_by_fifteen_hundredths(): void
    {
        $engine = new BirdEscapeEngine();

        for ($round = 1; $round <= 200; $round++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), $round, 500);
            $this->assertGreaterThanOrEqual(100, $result->crashMultiplierHundredths);
            $this->assertLessThanOrEqual(1500, $result->crashMultiplierHundredths, "Round $round exceeded 15.00x max multiplier");
        }
    }

    public function test_multiplier_distribution_buckets_align_with_target_probabilities(): void
    {
        $engine = new BirdEscapeEngine();
        $seed = bin2hex(random_bytes(32));
        $totalRounds = 10_000;

        $tier1Count = 0; // 100 - 120 (40%)
        $tier2Count = 0; // 121 - 150 (20%)
        $tier3Count = 0; // 151 - 250 (20%)
        $tier4Count = 0; // 251 - 400 (10%)
        $tier5Count = 0; // 401 - 500 (7%)
        $tier6Count = 0; // 501 - 1500 (3%)

        for ($round = 1; $round <= $totalRounds; $round++) {
            $m = $engine->resolve($seed, $round, 500)->crashMultiplierHundredths;
            $this->assertGreaterThanOrEqual(100, $m);
            $this->assertLessThanOrEqual(1500, $m);

            if ($m >= 100 && $m <= 120) {
                $tier1Count++;
            } elseif ($m >= 121 && $m <= 150) {
                $tier2Count++;
            } elseif ($m >= 151 && $m <= 250) {
                $tier3Count++;
            } elseif ($m >= 251 && $m <= 400) {
                $tier4Count++;
            } elseif ($m >= 401 && $m <= 500) {
                $tier5Count++;
            } elseif ($m >= 501 && $m <= 1500) {
                $tier6Count++;
            }
        }

        // Check within +/- 2.5% statistical tolerance for 10,000 draws
        $this->assertEqualsWithDelta(0.40, $tier1Count / $totalRounds, 0.025, 'Tier 1 (1.00-1.20) should be ~40%');
        $this->assertEqualsWithDelta(0.20, $tier2Count / $totalRounds, 0.025, 'Tier 2 (1.20-1.50) should be ~20%');
        $this->assertEqualsWithDelta(0.20, $tier3Count / $totalRounds, 0.025, 'Tier 3 (1.51-2.50) should be ~20%');
        $this->assertEqualsWithDelta(0.10, $tier4Count / $totalRounds, 0.020, 'Tier 4 (2.51-4.00) should be ~10%');
        $this->assertEqualsWithDelta(0.07, $tier5Count / $totalRounds, 0.015, 'Tier 5 (4.00-5.00) should be ~7%');
        $this->assertEqualsWithDelta(0.03, $tier6Count / $totalRounds, 0.015, 'Tier 6 (5.10-15.00) should be ~3%');
    }

    public function test_digest_changes_if_any_input_changes(): void
    {
        $engine = new BirdEscapeEngine();
        $seed = bin2hex(random_bytes(32));
        $result = $engine->resolve($seed, 1, 500);

        $this->assertNotSame($result->digest, $engine->resolve($seed, 2, 500)->digest);
        $this->assertNotSame($result->digest, $engine->resolve(bin2hex(random_bytes(32)), 1, 500)->digest);
    }

    public function test_rejects_an_out_of_range_house_edge(): void
    {
        $engine = new BirdEscapeEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), 1, 10_001);
    }

    public function test_growth_curve_is_constant_uniform_and_starts_at_one_hundred(): void
    {
        // Floor is 100 (1.00x) at t=0 — flight always starts at break-even. From
        // there every full 1.00x step (1.00x->2.00x, 2.00x->3.00x, ...) takes the
        // same duration: 4000ms by default, per the user's requested pacing.
        $this->assertSame(100, BirdEscapeEngine::multiplierHundredthsAtElapsedMs(0, 4000));
        $this->assertSame(150, BirdEscapeEngine::multiplierHundredthsAtElapsedMs(2000, 4000));
        $this->assertSame(200, BirdEscapeEngine::multiplierHundredthsAtElapsedMs(4000, 4000));
        $this->assertSame(300, BirdEscapeEngine::multiplierHundredthsAtElapsedMs(8000, 4000));
        $this->assertSame(400, BirdEscapeEngine::multiplierHundredthsAtElapsedMs(12000, 4000));

        $previous = 100;
        for ($elapsedMs = 100; $elapsedMs <= 30_000; $elapsedMs += 100) {
            $current = BirdEscapeEngine::multiplierHundredthsAtElapsedMs($elapsedMs, 4000);
            $this->assertGreaterThanOrEqual($previous, $current);
            $this->assertGreaterThanOrEqual(100, $current);
            $previous = $current;
        }
    }
}
