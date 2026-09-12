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

    public function test_daily_tier_quota_caps_prevent_excess_high_multiplier_rounds(): void
    {
        $engine = new BirdEscapeEngine();

        // 1. When dailyCountAbove25x >= 2, no round can exceed 25.00x (2500 hundredths)
        for ($round = 1; $round <= 200; $round++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), $round, 500, dailyCountAbove25x: 2);
            $this->assertLessThanOrEqual(2500, $result->crashMultiplierHundredths, "Round $round exceeded 25.00x despite daily quota being full");
        }

        // 2. When dailyCountBetween20xAnd25x >= 5, no round can be in [2000, 2500]
        for ($round = 1; $round <= 200; $round++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), $round, 500, dailyCountAbove25x: 2, dailyCountBetween20xAnd25x: 5);
            $this->assertLessThan(2000, $result->crashMultiplierHundredths, "Round $round landed in [20x, 25x] despite quota being full");
        }

        // 3. When dailyCountBetween15xAnd20x >= 10, no round can be in [1500, 2000]
        for ($round = 1; $round <= 200; $round++) {
            $result = $engine->resolve(
                bin2hex(random_bytes(32)),
                $round,
                500,
                dailyCountAbove25x: 2,
                dailyCountBetween20xAnd25x: 5,
                dailyCountBetween15xAnd20x: 10
            );
            $this->assertLessThan(1500, $result->crashMultiplierHundredths, "Round $round landed in [15x, 20x] despite quota being full");
        }
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
