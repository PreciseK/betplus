<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\BalancedHybridParams;
use App\Domain\Games\Economics\BalancedHybridTicketStrategy;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BalancedHybridTicketStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_a_stake_within_the_kelly_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $strategy = new BalancedHybridTicketStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Cap = 100,000,000 * 500 / 10,000 = 5,000,000 kobo.
        $strategy->assertAcceptable('BLACKRED', 4_000_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_stake_over_the_kelly_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $strategy = new BalancedHybridTicketStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BLACKRED', 6_000_000, EconomicsContext::forTicket());
    }
}
