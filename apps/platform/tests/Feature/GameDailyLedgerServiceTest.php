<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\GameDailyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameDailyLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_stakes_and_prizes_and_reports_net_ggr(): void
    {
        $ledger = app(GameDailyLedgerService::class);

        $ledger->recordSettlement('BLACKRED', 1_000, 0);
        $ledger->recordSettlement('BLACKRED', 1_000, 3_000);

        $this->assertSame(-1_000, $ledger->netGgrTodayKobo('BLACKRED'));
    }

    public function test_keeps_separate_totals_per_game(): void
    {
        $ledger = app(GameDailyLedgerService::class);

        $ledger->recordSettlement('BLACKRED', 1_000, 0);
        $ledger->recordSettlement('HERITAGE', 5_000, 0);

        $this->assertSame(1_000, $ledger->netGgrTodayKobo('BLACKRED'));
        $this->assertSame(5_000, $ledger->netGgrTodayKobo('HERITAGE'));
    }

    public function test_a_game_with_no_settlements_today_reports_zero(): void
    {
        $this->assertSame(0, app(GameDailyLedgerService::class)->netGgrTodayKobo('BLACKRED'));
    }
}
