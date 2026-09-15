<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Models\GameEconomicsConfig;
use Tests\TestCase;

final class GameEconomicsModelGateTest extends TestCase
{
    private function config(string $activeModel, array $params = [], string $gameCode = 'BLACKRED'): GameEconomicsConfig
    {
        $config = new GameEconomicsConfig();
        $config->activeModel = $activeModel;
        $config->paramsJson = $params;
        $config->gameCode = $gameCode;

        return $config;
    }

    public function test_fixed_rtp_needs_no_params(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('FIXED_RTP'));

        $this->assertEmpty($errors);
    }

    public function test_balanced_hybrid_requires_a_kelly_factor_within_range(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', ['kelly_factor_basis_points' => 300]));

        $this->assertEmpty($errors);
    }

    public function test_balanced_hybrid_without_a_kelly_factor_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('kelly_factor_basis_points', $errors[0]);
    }

    public function test_balanced_hybrid_with_an_out_of_range_kelly_factor_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', ['kelly_factor_basis_points' => 5000]));

        $this->assertNotEmpty($errors);
    }

    public function test_an_unknown_model_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('NOT_A_REAL_MODEL'));

        $this->assertNotEmpty($errors);
    }

    public function test_pari_mutuel_pool_requires_a_rake_at_or_above_the_rtp_ceiling_floor(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', ['rake_bps' => 1500, 'pool_window_minutes' => 60], 'BLACKRED'));

        $this->assertEmpty($errors);
    }

    public function test_pari_mutuel_pool_with_a_rake_below_the_floor_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', ['rake_bps' => 1199, 'pool_window_minutes' => 60], 'BLACKRED'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('rake_bps', $errors[0]);
    }

    public function test_pari_mutuel_pool_without_a_pool_window_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', ['rake_bps' => 1500], 'BLACKRED'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('pool_window_minutes', $errors[0]);
    }

    public function test_heritage_pari_mutuel_pool_requires_tier_allocation_summing_to_10000(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', [
            'rake_bps' => 1500, 'pool_window_minutes' => 60,
            'tier_allocation_bps' => ['5' => 5000, '4' => 3000, '3' => 1500, '2' => 500],
        ], 'HERITAGE'));

        $this->assertEmpty($errors);
    }

    public function test_heritage_pari_mutuel_pool_with_a_tier_allocation_not_summing_to_10000_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', [
            'rake_bps' => 1500, 'pool_window_minutes' => 60,
            'tier_allocation_bps' => ['5' => 5000, '4' => 3000, '3' => 1500, '2' => 400],
        ], 'HERITAGE'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('tier_allocation_bps', $errors[0]);
    }

    public function test_heritage_pari_mutuel_pool_without_tier_allocation_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', ['rake_bps' => 1500, 'pool_window_minutes' => 60], 'HERITAGE'));

        $this->assertNotEmpty($errors);
    }

    public function test_blackred_pari_mutuel_pool_does_not_require_tier_allocation(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL', ['rake_bps' => 1500, 'pool_window_minutes' => 60], 'BLACKRED'));

        $this->assertEmpty($errors);
    }

    public function test_daily_loss_stop_requires_a_daily_loss_cap_within_range(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('DAILY_LOSS_STOP', ['daily_loss_cap_kobo' => 500_000_00]));

        $this->assertEmpty($errors);
    }

    public function test_daily_loss_stop_without_a_daily_loss_cap_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('DAILY_LOSS_STOP'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('daily_loss_cap_kobo', $errors[0]);
    }

    public function test_daily_loss_stop_with_a_non_positive_daily_loss_cap_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('DAILY_LOSS_STOP', ['daily_loss_cap_kobo' => 0]));

        $this->assertNotEmpty($errors);
    }

    public function test_balanced_hybrid_accepts_an_optional_reserve_siphon_within_range(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', [
            'kelly_factor_basis_points' => 300,
            'reserve_siphon_bps' => 1000,
        ]));

        $this->assertEmpty($errors);
    }

    public function test_balanced_hybrid_with_an_out_of_range_reserve_siphon_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', [
            'kelly_factor_basis_points' => 300,
            'reserve_siphon_bps' => 9999,
        ]));

        $this->assertNotEmpty($errors);
    }
}
