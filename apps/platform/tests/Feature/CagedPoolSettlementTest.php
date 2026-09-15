<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\CagedPoolSettlementService;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Domain\Games\Economics\PoolDrawService;
use App\Domain\Wallet\WalletService;
use App\Models\GameEconomicsConfig;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PoolDraw;
use App\Models\PoolEntry;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CagedPoolSettlementTest extends TestCase
{
    use RefreshDatabase;

    private PariMutuelPoolParams $params;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);
        Queue::fake();
        $this->params = PariMutuelPoolParams::fromArray(['rake_bps' => 1500, 'pool_window_minutes' => 60]);

        GameEconomicsConfig::create([
            'gameCode' => 'CAGED',
            'version' => 'pool-v1',
            'status' => 'published',
            'activeModel' => 'PARI_MUTUEL_POOL',
            'paramsJson' => ['rake_bps' => 1500, 'pool_window_minutes' => 60],
            'effectiveAt' => now()->subMinute(),
            'publishedAt' => now()->subMinute(),
        ]);
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Player ' . $msisdn, 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', $msisdn),
        ]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', $player->id);

        return [$player, $token];
    }

    public function test_settling_a_pool_pays_every_target_the_draw_met_or_beat_and_balances_the_ledger(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348030002001');
        [, $tokenB] = $this->signedInPlayer('+2348030002002');
        [, $tokenC] = $this->signedInPlayer('+2348030002003');

        // Mixing a low and a high target exercises real proportional splitting
        // whichever way the draw actually falls, without needing to fix the RNG —
        // the invariants below (ledger balances, payouts proportional to stake,
        // non-winners get zero, threshold winners are exactly those <= the draw)
        // hold regardless of the outcome.
        $this->withToken($tokenA)->postJson('/v1/caged/tickets', ['target_birds' => 1, 'stake_kobo' => 10_000, 'idempotency_key' => 'cg-settle-a'])->assertOk();
        $this->withToken($tokenB)->postJson('/v1/caged/tickets', ['target_birds' => 1, 'stake_kobo' => 30_000, 'idempotency_key' => 'cg-settle-b'])->assertOk();
        $this->withToken($tokenC)->postJson('/v1/caged/tickets', ['target_birds' => 5, 'stake_kobo' => 20_000, 'idempotency_key' => 'cg-settle-c'])->assertOk();

        $pool = PoolDraw::where('gameCode', 'CAGED')->sole();
        app(CagedPoolSettlementService::class)->settle($pool, $this->params);

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get();
        $this->assertTrue($entries->every(fn (PoolEntry $e) => $e->won !== null));

        $escapedBirds = $pool->fresh()->drawnOutcomeJson['escapedBirds'];
        foreach ($entries as $entry) {
            $shouldWin = ((int) $entry->predictionJson[0]) <= $escapedBirds;
            $this->assertSame($shouldWin, $entry->won);
        }

        $winners = $entries->where('won', true);
        if ($winners->isEmpty()) {
            $settledPool = $pool->fresh();
            $this->assertGreaterThan(0, $settledPool->netPoolKobo);
            $nextPool = PoolDraw::where('gameCode', 'CAGED')->where('id', '!=', $pool->id)->sole();
            $this->assertSame($settledPool->netPoolKobo, $nextPool->rolloverInKobo);
        } else {
            $totalWinningStake = $winners->sum('stakeKobo');
            foreach ($winners as $winner) {
                $expectedShare = $winner->stakeKobo / $totalWinningStake;
                $this->assertEqualsWithDelta($expectedShare, $winner->payoutKobo / $pool->fresh()->netPoolKobo, 0.01);
                $this->assertGreaterThan(0, $winner->payoutKobo);
            }
        }
        foreach ($entries->where('won', false) as $loser) {
            $this->assertSame(0, $loser->payoutKobo);
        }

        foreach (Ticket::whereIn('id', $entries->pluck('ticketId'))->get() as $ticket) {
            $this->assertSame('SETTLED', $ticket->status);
            $this->assertNotNull($ticket->outcome);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);

        $this->assertSame(1, PoolDraw::where('gameCode', 'CAGED')->where('status', 'open')->count());
    }

    public function test_settling_a_pool_with_no_entries_produces_no_payouts_and_no_ledger_activity(): void
    {
        $pool = app(PoolDrawService::class)->openPool('CAGED', null, 60, 0, null);

        $result = app(CagedPoolSettlementService::class)->settle($pool, $this->params);

        $this->assertTrue($result);
        $this->assertSame('settled', $pool->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_pool_already_settled_by_a_concurrent_run_is_not_settled_twice(): void
    {
        $pool = app(PoolDrawService::class)->openPool('CAGED', null, 60, 0, null);
        PoolDraw::where('id', $pool->id)->update(['status' => 'closed']);

        $result = app(CagedPoolSettlementService::class)->settle($pool, $this->params);

        $this->assertFalse($result);
    }
}
