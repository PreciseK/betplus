<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Games\Economics\GameReserveFundSiphonService;
use App\Models\GameEconomicsConfig;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameReserveFundSiphonServiceTest extends TestCase
{
    use RefreshDatabase;

    private function publishConfig(string $gameCode, array $params): void
    {
        GameEconomicsConfig::create([
            'gameCode' => $gameCode,
            'version' => 'v1',
            'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID',
            'paramsJson' => $params,
            'effectiveAt' => now()->subDay(),
            'publishedAt' => now()->subDay(),
        ]);
    }

    public function test_siphons_the_configured_percentage_of_a_days_net_ggr_into_the_reserve_fund(): void
    {
        $day = CarbonImmutable::yesterday();
        $this->publishConfig('BLACKRED', ['kelly_factor_basis_points' => 300, 'reserve_siphon_bps' => 1_000]);

        app(GameDailyLedgerService::class);
        \App\Models\GameDailyLedger::create([
            'gameCode' => 'BLACKRED',
            'ledgerDate' => $day->toDateString(),
            'grossStakesKobo' => 100_000,
            'grossPrizesKobo' => 40_000,
        ]);

        $siphoned = app(GameReserveFundSiphonService::class)->siphonDay($day);

        $this->assertSame(1, $siphoned);

        // Net GGR = 60,000; 10% siphon = 6,000.
        $reserveAccount = LedgerAccount::where('type', 'RESERVE_FUND')->first();
        $this->assertNotNull($reserveAccount);
        $this->assertSame(6_000, (int) LedgerEntry::where('accountId', $reserveAccount->id)->where('direction', 'credit')->sum('amountKobo'));
    }

    public function test_skips_games_without_a_reserve_siphon_configured(): void
    {
        $day = CarbonImmutable::yesterday();
        $this->publishConfig('BLACKRED', ['kelly_factor_basis_points' => 300]);

        \App\Models\GameDailyLedger::create([
            'gameCode' => 'BLACKRED',
            'ledgerDate' => $day->toDateString(),
            'grossStakesKobo' => 100_000,
            'grossPrizesKobo' => 40_000,
        ]);

        $this->assertSame(0, app(GameReserveFundSiphonService::class)->siphonDay($day));
        $this->assertNull(LedgerAccount::where('type', 'RESERVE_FUND')->first());
    }

    public function test_skips_games_that_lost_money_that_day(): void
    {
        $day = CarbonImmutable::yesterday();
        $this->publishConfig('BLACKRED', ['kelly_factor_basis_points' => 300, 'reserve_siphon_bps' => 1_000]);

        \App\Models\GameDailyLedger::create([
            'gameCode' => 'BLACKRED',
            'ledgerDate' => $day->toDateString(),
            'grossStakesKobo' => 40_000,
            'grossPrizesKobo' => 100_000,
        ]);

        $this->assertSame(0, app(GameReserveFundSiphonService::class)->siphonDay($day));
    }
}
