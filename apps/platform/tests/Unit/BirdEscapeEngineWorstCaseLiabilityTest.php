<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use Tests\TestCase;

final class BirdEscapeEngineWorstCaseLiabilityTest extends TestCase
{
    public function test_worst_case_liability_is_stake_times_the_absolute_max_multiplier(): void
    {
        // 200,000 kobo staked, worst case pays at the 15.00x absolute ceiling.
        $this->assertSame(3_000_000, BirdEscapeEngine::worstCaseLiabilityKobo(200_000));
    }

    public function test_zero_stake_has_zero_worst_case_liability(): void
    {
        $this->assertSame(0, BirdEscapeEngine::worstCaseLiabilityKobo(0));
    }
}
