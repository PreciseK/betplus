<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\DailyLossStopParams;
use App\Domain\Games\Economics\DailyLossStopStrategy;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Ticket\TicketEligibilityException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyLossStopStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_stakes_while_under_the_daily_loss_cap(): void
    {
        $ledger = app(GameDailyLedgerService::class);
        $strategy = new DailyLossStopStrategy($ledger, DailyLossStopParams::fromArray(['daily_loss_cap_kobo' => 10_000]));

        $strategy->assertAcceptable('BLACKRED', 1_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1);
    }

    public function test_rejects_stakes_once_the_daily_loss_cap_is_crossed(): void
    {
        $ledger = app(GameDailyLedgerService::class);
        $ledger->recordSettlement('BLACKRED', 1_000, 12_000); // net GGR = -11,000

        $strategy = new DailyLossStopStrategy($ledger, DailyLossStopParams::fromArray(['daily_loss_cap_kobo' => 10_000]));

        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BLACKRED', 1_000, EconomicsContext::forTicket());
    }

    public function test_a_different_games_losses_do_not_suspend_this_one(): void
    {
        $ledger = app(GameDailyLedgerService::class);
        $ledger->recordSettlement('HERITAGE', 1_000, 12_000);

        $strategy = new DailyLossStopStrategy($ledger, DailyLossStopParams::fromArray(['daily_loss_cap_kobo' => 10_000]));

        $strategy->assertAcceptable('BLACKRED', 1_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1);
    }
}
