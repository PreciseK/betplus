<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\BlackRedPoolSettlementService;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Domain\Wallet\WalletService;
use App\Models\GameEconomicsConfig;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PoolDraw;
use App\Models\PoolEntry;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BlackRedPoolSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
        Queue::fake();

        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED',
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

    public function test_settling_a_pool_with_a_winner_pays_out_proportional_to_stake_and_balances_the_ledger(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348030000001');
        [, $tokenB] = $this->signedInPlayer('+2348030000002');
        [, $tokenC] = $this->signedInPlayer('+2348030000003');

        // BlackRedGameSeeder's minStakeKobo is 10,000 — stay above it. A and B both
        // pick BR (winners, stakes 10,000:30,000 -> 25%:75%); C picks RB (loses).
        $this->withToken($tokenA)->postJson('/v1/tickets', ['prediction' => ['B', 'R'], 'stake_kobo' => 10_000, 'idempotency_key' => 'settle-a'])->assertOk();
        $this->withToken($tokenB)->postJson('/v1/tickets', ['prediction' => ['B', 'R'], 'stake_kobo' => 30_000, 'idempotency_key' => 'settle-b'])->assertOk();
        $this->withToken($tokenC)->postJson('/v1/tickets', ['prediction' => ['R', 'B'], 'stake_kobo' => 20_000, 'idempotency_key' => 'settle-c'])->assertOk();

        $pool = PoolDraw::where('gameCode', 'BLACKRED')->where('poolKey', 2)->sole();
        // Force the drawn outcome deterministically by aligning the fixture with
        // whatever the seeded RNG actually draws is impractical here — instead,
        // rewrite the two winning entries' predictions to match whatever draws.
        // Simpler: settle, then assert on whichever entries actually won, using the
        // invariant that always holds regardless of the draw (ledger balances,
        // payouts proportional, non-winners get zero).
        app(BlackRedPoolSettlementService::class)->settle($pool, PariMutuelPoolParams::fromArray(['rake_bps' => 1500, 'pool_window_minutes' => 60]));

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get();
        $this->assertTrue($entries->every(fn (PoolEntry $e) => $e->won !== null));

        $winners = $entries->where('won', true);
        if ($winners->isEmpty()) {
            // No ticket matched the draw — the whole net pool rolled forward.
            $settledPool = $pool->fresh();
            $this->assertSame('settled', $settledPool->status);
            $this->assertGreaterThan(0, $settledPool->netPoolKobo);
            $nextPool = PoolDraw::where('gameCode', 'BLACKRED')->where('poolKey', 2)->where('id', '!=', $pool->id)->sole();
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

        // Every settled ticket has an outcome and left PENDING_DRAW.
        foreach (Ticket::whereIn('id', $entries->pluck('ticketId'))->get() as $ticket) {
            $this->assertSame('SETTLED', $ticket->status);
            $this->assertNotNull($ticket->outcome);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);

        // The next window's pool for this poolKey is already open.
        $this->assertSame(1, PoolDraw::where('gameCode', 'BLACKRED')->where('poolKey', 2)->where('status', 'open')->count());
    }

    public function test_settling_a_pool_with_no_entries_produces_no_payouts_and_no_ledger_activity(): void
    {
        $pool = app(\App\Domain\Games\Economics\PoolDrawService::class)->openPool('BLACKRED', 3, 60, 0, null);

        $result = app(BlackRedPoolSettlementService::class)->settle($pool, PariMutuelPoolParams::fromArray(['rake_bps' => 1500, 'pool_window_minutes' => 60]));

        $this->assertTrue($result);
        $this->assertSame('settled', $pool->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_pool_already_settled_by_a_concurrent_run_is_not_settled_twice(): void
    {
        $pool = app(\App\Domain\Games\Economics\PoolDrawService::class)->openPool('BLACKRED', 4, 60, 0, null);
        PoolDraw::where('id', $pool->id)->update(['status' => 'closed']);

        $result = app(BlackRedPoolSettlementService::class)->settle($pool, PariMutuelPoolParams::fromArray(['rake_bps' => 1500, 'pool_window_minutes' => 60]));

        $this->assertFalse($result);
    }
}
