<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\BalancedHybridCrashStrategy;
use App\Domain\Games\Economics\BalancedHybridParams;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\CagedCrash\RoundLifecycleService;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use Database\Seeders\CagedCrashGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BalancedHybridCrashStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedCrashGameSeeder::class);
    }

    public function test_accepts_a_bet_whose_worst_case_exposure_stays_under_the_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Cap = 100,000,000 * 500 / 10,000 = 5,000,000 kobo.
        // Worst case for a 10,000 kobo stake = 10,000 * 15 = 150,000 kobo.
        $strategy->assertAcceptable('BIRDESCAPE', 10_000, EconomicsContext::forCrashRound($round));

        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_bet_whose_worst_case_exposure_would_exceed_the_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Worst case for a 400,000 kobo stake = 400,000 * 15 = 6,000,000 kobo > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BIRDESCAPE', 400_000, EconomicsContext::forCrashRound($round));
    }

    public function test_accounts_for_the_rounds_existing_exposure_before_accepting_a_new_bet(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $round->update(['exposureKobo' => 4_900_000]); // already close to the 5,000,000 cap
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Worst case for a 10,000 kobo stake = 150,000 kobo; 4,900,000 + 150,000 > 5,000,000.
        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BIRDESCAPE', 10_000, EconomicsContext::forCrashRound($round));
    }
}
