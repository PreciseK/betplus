<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Models\GameEconomicsConfig;
use Tests\TestCase;

final class GameEconomicsModelGateTest extends TestCase
{
    private function config(string $activeModel, array $params = []): GameEconomicsConfig
    {
        $config = new GameEconomicsConfig();
        $config->activeModel = $activeModel;
        $config->paramsJson = $params;

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

    public function test_daily_loss_stop_and_pari_mutuel_pool_are_selectable_with_no_params_validated_yet(): void
    {
        $this->assertEmpty(app(GameEconomicsModelGate::class)->validate($this->config('DAILY_LOSS_STOP')));
        $this->assertEmpty(app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL')));
    }
}
