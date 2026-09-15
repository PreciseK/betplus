<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\PoolPayoutCalculator;
use Tests\TestCase;

final class PoolPayoutCalculatorTest extends TestCase
{
    public function test_a_single_winner_takes_the_whole_net_pool(): void
    {
        $result = (new PoolPayoutCalculator())->split(100_000, [1 => 500]);

        $this->assertSame([1 => 100_000], $result['payouts']);
        $this->assertSame(0, $result['remainderKobo']);
    }

    public function test_multiple_winners_split_proportional_to_stake(): void
    {
        // 500 : 1500 -> 25% : 75% of the net pool.
        $result = (new PoolPayoutCalculator())->split(100_000, [1 => 500, 2 => 1_500]);

        $this->assertSame(25_000, $result['payouts'][1]);
        $this->assertSame(75_000, $result['payouts'][2]);
        $this->assertSame(0, $result['remainderKobo']);
    }

    public function test_truncation_remainder_is_reported_not_silently_dropped(): void
    {
        // 100 split 1:1:1 truncates to 33 each, leaving 1 kobo over.
        $result = (new PoolPayoutCalculator())->split(100, [1 => 10, 2 => 10, 3 => 10]);

        $this->assertSame(33, $result['payouts'][1]);
        $this->assertSame(33, $result['payouts'][2]);
        $this->assertSame(33, $result['payouts'][3]);
        $this->assertSame(1, $result['remainderKobo']);
    }

    public function test_no_winners_reports_the_whole_pool_as_remainder(): void
    {
        $result = (new PoolPayoutCalculator())->split(100_000, []);

        $this->assertSame([], $result['payouts']);
        $this->assertSame(100_000, $result['remainderKobo']);
    }
}
