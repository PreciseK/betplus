<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\BalancedHybridCrashStrategy;
use App\Domain\Games\Economics\BalancedHybridTicketStrategy;
use App\Domain\Games\Economics\DailyLossStopStrategy;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\FixedRtpStrategy;
use App\Models\GameEconomicsConfig;
use Tests\TestCase;

final class EconomicsModelStrategyFactoryTest extends TestCase
{
    private function config(string $activeModel): GameEconomicsConfig
    {
        $config = new GameEconomicsConfig();
        $config->activeModel = $activeModel;
        $config->paramsJson = ['kelly_factor_basis_points' => 300, 'daily_loss_cap_kobo' => 500_000_00];

        return $config;
    }

    public function test_no_published_config_yields_fixed_rtp_for_ticket_games(): void
    {
        $this->assertInstanceOf(FixedRtpStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame(null));
    }

    public function test_fixed_rtp_model_yields_fixed_rtp_strategy(): void
    {
        $this->assertInstanceOf(FixedRtpStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame($this->config('FIXED_RTP')));
    }

    public function test_balanced_hybrid_model_yields_the_ticket_strategy_for_ticket_games(): void
    {
        $this->assertInstanceOf(BalancedHybridTicketStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame($this->config('BALANCED_HYBRID')));
    }

    public function test_balanced_hybrid_model_yields_the_crash_strategy_for_the_crash_game(): void
    {
        $this->assertInstanceOf(BalancedHybridCrashStrategy::class, app(EconomicsModelStrategyFactory::class)->forCrashGame($this->config('BALANCED_HYBRID')));
    }

    public function test_daily_loss_stop_model_yields_the_same_strategy_for_both_engine_shapes(): void
    {
        $factory = app(EconomicsModelStrategyFactory::class);
        $this->assertInstanceOf(DailyLossStopStrategy::class, $factory->forTicketGame($this->config('DAILY_LOSS_STOP')));
        $this->assertInstanceOf(DailyLossStopStrategy::class, $factory->forCrashGame($this->config('DAILY_LOSS_STOP')));
    }

    public function test_pari_mutuel_pool_falls_back_to_fixed_rtp_for_now(): void
    {
        $this->assertInstanceOf(FixedRtpStrategy::class, app(EconomicsModelStrategyFactory::class)->forCrashGame($this->config('PARI_MUTUEL_POOL')));
    }
}
