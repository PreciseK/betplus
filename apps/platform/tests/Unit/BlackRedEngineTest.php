<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\BlackRed\BlackRedEngine;
use App\Domain\Games\Engine\BlackRed\EngineTier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlackRedEngineTest extends TestCase
{
    /** @return list<EngineTier> */
    private function tiers(): array
    {
        return [
            new EngineTier(1, 185),
            new EngineTier(2, 360),
            new EngineTier(3, 700),
            new EngineTier(4, 1350),
            new EngineTier(5, 2600),
        ];
    }

    public function test_resolve_and_replay_are_byte_identical_for_the_same_inputs(): void
    {
        $engine = new BlackRedEngine();
        $seed = bin2hex(random_bytes(32));

        $resolved = $engine->resolve($seed, ['B', 'R', 'B'], 100_000, $this->tiers());
        $replayed = $engine->replay($seed, ['B', 'R', 'B'], 100_000, $this->tiers());

        $this->assertSame($resolved->result, $replayed->result);
        $this->assertSame($resolved->won, $replayed->won);
        $this->assertSame($resolved->grossPrizeKobo, $replayed->grossPrizeKobo);
        $this->assertSame($resolved->digest, $replayed->digest);
    }

    public function test_different_seeds_produce_different_results_with_overwhelming_probability(): void
    {
        $engine = new BlackRedEngine();
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = implode('', $engine->resolve(bin2hex(random_bytes(32)), ['B', 'R', 'B', 'R', 'B'], 100_000, $this->tiers())->result);
        }

        $this->assertGreaterThan(1, count(array_unique($results)), 'Twenty random seeds produced an identical 5-position draw every time.');
    }

    public function test_an_exact_match_of_every_position_wins(): void
    {
        $engine = new BlackRedEngine();
        $seed = bin2hex(random_bytes(32));
        $result = $engine->resolve($seed, ['B'], 100_000, $this->tiers());

        // The draw for one position is whatever DeckDraw produced; assert consistency
        // between the engine's own "won" flag and comparing prediction to result.
        $this->assertSame($result->result === ['B'], $result->won);
    }

    public function test_one_wrong_position_is_a_loss_with_no_partial_prize(): void
    {
        $engine = new BlackRedEngine();
        // Force a known losing case by finding a seed whose 2-position draw isn't ['B','B'].
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, ['B', 'B'], 100_000, $this->tiers());
        } while ($result->result === ['B', 'B']);

        $this->assertFalse($result->won);
        $this->assertSame(0, $result->grossPrizeKobo);
    }

    public function test_gross_prize_is_stake_times_tier_multiplier_on_a_win(): void
    {
        $engine = new BlackRedEngine();
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, ['B'], 100_000, $this->tiers());
        } while (!$result->won);

        $this->assertSame(185_000, $result->grossPrizeKobo); // 100_000 * 1.85
    }

    public function test_rejects_a_prediction_longer_than_five_positions(): void
    {
        $engine = new BlackRedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), ['B', 'R', 'B', 'R', 'B', 'R'], 100_000, $this->tiers());
    }

    public function test_rejects_an_empty_prediction(): void
    {
        $engine = new BlackRedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), [], 100_000, $this->tiers());
    }
}
