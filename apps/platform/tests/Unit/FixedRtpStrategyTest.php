<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\FixedRtpStrategy;
use Tests\TestCase;

final class FixedRtpStrategyTest extends TestCase
{
    public function test_never_rejects_any_stake(): void
    {
        $strategy = new FixedRtpStrategy();

        $strategy->assertAcceptable('BLACKRED', 1_000_000_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }
}
