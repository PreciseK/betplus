<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\BalancedHybridParams;
use Tests\TestCase;

final class BalancedHybridParamsTest extends TestCase
{
    public function test_reads_the_kelly_factor_from_the_params_array(): void
    {
        $params = BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 300]);

        $this->assertSame(300, $params->kellyFactorBasisPoints);
    }

    public function test_defaults_to_zero_when_the_key_is_missing(): void
    {
        $params = BalancedHybridParams::fromArray([]);

        $this->assertSame(0, $params->kellyFactorBasisPoints);
    }
}
