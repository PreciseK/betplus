<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\Heritage\HeritageBoard;
use App\Domain\Games\Engine\Heritage\HeritageEngine;
use App\Domain\Games\Engine\Heritage\HeritageTier;
use PHPUnit\Framework\TestCase;

final class HeritageEngineTest extends TestCase
{
    private HeritageEngine $engine;

    /** @var list<HeritageTier> */
    private array $tiers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new HeritageEngine();
        $this->tiers = [
            new HeritageTier('TIER_JACKPOT', 100, 10000, 'cash'),
            new HeritageTier('TIER_HIGH', 900, 200, 'cash'),
            new HeritageTier('TIER_LOSS', 9000, 0, 'none'),
        ];
    }

    public function test_engine_resolves_deterministically(): void
    {
        $seedHex = bin2hex(random_bytes(32));
        $picks = [0, 1, 2, 3, 4];

        $res1 = $this->engine->resolve('ticket-1', $seedHex, 10000, $this->tiers, $picks, 'yoruba', 'king');
        $res2 = $this->engine->resolve('ticket-1', $seedHex, 10000, $this->tiers, $picks, 'yoruba', 'king');

        $this->assertSame($res1->outcomeTier, $res2->outcomeTier);
        $this->assertSame($res1->grossPrizeKobo, $res2->grossPrizeKobo);
        $this->assertSame($res1->board, $res2->board);
        $this->assertSame($res1->winningPositions, $res2->winningPositions);
        $this->assertSame($res1->matchCount, $res2->matchCount);
        $this->assertSame($res1->digest, $res2->digest);
    }

    public function test_replay_matches_resolve_identically(): void
    {
        $seedHex = bin2hex(random_bytes(32));
        $picks = [1, 2, 3, 5, 8];

        $resolved = $this->engine->resolve('ticket-2', $seedHex, 5000, $this->tiers, $picks, 'igbo', 'queen');
        $replayed = $this->engine->replay('ticket-2', $seedHex, 5000, $this->tiers, $picks, 'igbo', 'queen');

        $this->assertSame($resolved->digest, $replayed->digest);
        $this->assertSame($resolved->outcomeTier, $replayed->outcomeTier);
        $this->assertSame($resolved->board, $replayed->board);
    }

    public function test_winning_positions_contain_authentic_regalia_items(): void
    {
        $seedHex = bin2hex(random_bytes(32));
        $picks = [0, 1, 2, 3, 4];

        $res = $this->engine->resolve('ticket-3', $seedHex, 10000, $this->tiers, $picks, 'edo', 'king');

        $winningNumbers = array_map(fn (int $pos) => $res->board[$pos], $res->winningPositions);
        sort($winningNumbers);

        $this->assertSame([51, 52, 53, 54, 55], $winningNumbers);
        $this->assertCount(9, array_unique($res->board));
        $this->assertCount(5, $res->winningPositions);
    }

    public function test_draw_pool_outcome_draws_five_distinct_positions(): void
    {
        $seedHex = bin2hex(random_bytes(32));
        $drawn = $this->engine->drawPoolOutcome($seedHex);

        $this->assertCount(5, $drawn);
        $this->assertCount(5, array_unique($drawn));
        foreach ($drawn as $pos) {
            $this->assertGreaterThanOrEqual(0, $pos);
            $this->assertLessThanOrEqual(8, $pos);
        }
    }

    public function test_describe_returns_accurate_metadata(): void
    {
        $desc = $this->engine->describe();

        $this->assertSame('heritage', $desc['engine']);
        $this->assertSame(HeritageEngine::VERSION, $desc['engine_version']);
        $this->assertSame('HERITAGE', $desc['game_code']);
        $this->assertSame(HeritageBoard::BOARD_SIZE, $desc['board_size']);
        $this->assertSame(HeritageBoard::PICK_SIZE, $desc['pick_size']);
        $this->assertSame(['TIER_HIGH', 'TIER_JACKPOT', 'TIER_LOSS'], $desc['tier_names']);
    }
}
