<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payout\Float\FloatService;
use App\Models\FloatSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FloatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_no_snapshot_has_ever_been_taken(): void
    {
        $this->assertSame(0, app(FloatService::class)->currentFloatKobo());
    }

    public function test_returns_the_most_recently_polled_balance(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 10_000_000, 'alertState' => 'ok', 'polledAt' => now()->subMinutes(10)]);
        FloatSnapshot::create(['opayBalanceKobo' => 20_000_000, 'alertState' => 'ok', 'polledAt' => now()]);

        $this->assertSame(20_000_000, app(FloatService::class)->currentFloatKobo());
    }
}
