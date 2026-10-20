<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Heritage\HeritageEngineClient;
use App\Domain\Games\PrizeTable\HeritagePrizeTableResolver;
use App\Models\PrizeTable;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers HeritageEngineClient::resolveLocally() — the in-process fallback used
 * whenever apps/engine-heritage is unreachable (REQ-GEC: "matching the Heritage
 * engine spec"). No existing test exercised this path at all before; it's
 * reached here via a guaranteed-unreachable base URL rather than reflection, so
 * the real resolve() -> ConnectionException -> resolveLocally() wiring is what's
 * actually under test, not just the private method in isolation.
 */
final class HeritageEngineClientLocalFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
    }

    private function prizeTable(): PrizeTable
    {
        $table = app(HeritagePrizeTableResolver::class)->resolveFor('HERITAGE', 'LAG');
        $this->assertNotNull($table);

        return $table;
    }

    private function unreachableClient(): HeritageEngineClient
    {
        // Port 1 on loopback refuses instantly on every OS/CI runner — no DNS
        // lookup, no timeout wait — so resolve() deterministically falls through
        // its ConnectionException catch into resolveLocally() on every call.
        return new HeritageEngineClient('http://127.0.0.1:1', 1);
    }

    public function test_winning_positions_always_show_the_selected_tribes_exact_regalia(): void
    {
        $prizeTable = $this->prizeTable();
        $client = $this->unreachableClient();

        for ($i = 0; $i < 20; $i++) {
            $result = $client->resolve(
                "ticket-{$i}",
                bin2hex(random_bytes(32)),
                100_000,
                $prizeTable,
                [0, 1, 2, 3, 4],
                'edo',
                'king',
            );

            $winningNumbers = array_map(fn (int $position) => $result->board[$position], $result->winningPositions);
            sort($winningNumbers);
            $this->assertSame([51, 52, 53, 54, 55], $winningNumbers, 'edo:king must always own 51-55');
            $this->assertCount(9, array_unique($result->board), 'the board must never repeat a number');
        }
    }

    public function test_tradition_and_leader_never_affect_the_financial_outcome(): void
    {
        $prizeTable = $this->prizeTable();
        $client = $this->unreachableClient();
        $seedHex = bin2hex(random_bytes(32));

        $a = $client->resolve('t1', $seedHex, 100_000, $prizeTable, [0, 1, 2, 3, 4], 'edo', 'king');
        $b = $client->resolve('t1', $seedHex, 100_000, $prizeTable, [0, 1, 2, 3, 4], 'igbo', 'queen');

        // REQ-HG-051 — cosmetic only. The board's content is deliberately
        // different here (each shows its own tribe's regalia); everything that
        // actually determines payout must still be identical.
        $this->assertSame($a->outcomeTier, $b->outcomeTier);
        $this->assertSame($a->grossPrizeKobo, $b->grossPrizeKobo);
        $this->assertSame($a->matchCount, $b->matchCount);
        $this->assertSame($a->winningPositions, $b->winningPositions);
    }

    public function test_falls_back_to_a_fully_random_board_for_an_unrecognized_tradition(): void
    {
        $prizeTable = $this->prizeTable();
        $client = $this->unreachableClient();

        $result = $client->resolve('t1', bin2hex(random_bytes(32)), 100_000, $prizeTable, [0, 1, 2, 3, 4], 'atlantis', 'king');

        $this->assertCount(9, array_unique($result->board));
        $this->assertTrue(collect($result->board)->every(fn (int $n) => $n >= 1 && $n <= 90));
    }
}
