<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\HeritagePoolSettlementService;
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
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class HeritagePoolSettlementTest extends TestCase
{
    use RefreshDatabase;

    private PariMutuelPoolParams $params;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
        Queue::fake();
        $this->params = PariMutuelPoolParams::fromArray([
            'rake_bps' => 1500, 'pool_window_minutes' => 60,
            'tier_allocation_bps' => ['5' => 5000, '4' => 3000, '3' => 1500, '2' => 500],
        ]);

        GameEconomicsConfig::create([
            'gameCode' => 'HERITAGE',
            'version' => 'pool-v1',
            'status' => 'published',
            'activeModel' => 'PARI_MUTUEL_POOL',
            'paramsJson' => [
                'rake_bps' => 1500, 'pool_window_minutes' => 60,
                'tier_allocation_bps' => ['5' => 5000, '4' => 3000, '3' => 1500, '2' => 500],
            ],
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

    public function test_settling_a_pool_pays_each_tier_proportional_to_stake_and_balances_the_ledger(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348030003001');
        [, $tokenB] = $this->signedInPlayer('+2348030003002');
        [, $tokenC] = $this->signedInPlayer('+2348030003003');
        [, $tokenD] = $this->signedInPlayer('+2348030003004');

        // Draw will land on [0,1,2,3,4]. A and B both match all 5 (tier 5, stakes
        // 10,000:30,000 -> 25%:75% of the 5-tier's budget). C matches exactly 3
        // (picks 0,1,2 + two positions outside the drawn set). D matches only 1 —
        // a 5-of-9 pick against a 5-of-9 draw can never match fewer than 1
        // (pigeonhole: 5 winning + 5 non-winning needs 10 slots, the board has 9),
        // same structural floor Heritage's instant-ticket engine already documents.
        // 1 isn't a configured tier (2-5 only), so D wins nothing either way.
        $this->withToken($tokenA)->postJson('/v1/heritage/tickets', ['selected_positions' => [0, 1, 2, 3, 4], 'stake_kobo' => 10_000, 'idempotency_key' => 'hg-settle-a'])->assertOk();
        $this->withToken($tokenB)->postJson('/v1/heritage/tickets', ['selected_positions' => [0, 1, 2, 3, 4], 'stake_kobo' => 30_000, 'idempotency_key' => 'hg-settle-b'])->assertOk();
        $this->withToken($tokenC)->postJson('/v1/heritage/tickets', ['selected_positions' => [0, 1, 2, 5, 6], 'stake_kobo' => 20_000, 'idempotency_key' => 'hg-settle-c'])->assertOk();
        $this->withToken($tokenD)->postJson('/v1/heritage/tickets', ['selected_positions' => [5, 6, 7, 8, 0], 'stake_kobo' => 15_000, 'idempotency_key' => 'hg-settle-d'])->assertOk();

        $pool = PoolDraw::where('gameCode', 'HERITAGE')->sole();
        Http::fake(['*/engine/v1/draw-pool' => Http::response([
            'winning_positions' => [0, 1, 2, 3, 4],
            'engine_version' => 'heritage-1.0.0',
        ])]);

        app(HeritagePoolSettlementService::class)->settle($pool, $this->params);

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get()->keyBy('ticketId');
        $ticketA = Ticket::where('idempotencyKey', 'hg-settle-a')->first();
        $ticketB = Ticket::where('idempotencyKey', 'hg-settle-b')->first();
        $ticketC = Ticket::where('idempotencyKey', 'hg-settle-c')->first();
        $ticketD = Ticket::where('idempotencyKey', 'hg-settle-d')->first();

        $entryA = $entries[$ticketA->id];
        $entryB = $entries[$ticketB->id];
        $entryC = $entries[$ticketC->id];
        $entryD = $entries[$ticketD->id];

        $this->assertSame(5, $entryA->matchTier);
        $this->assertSame(5, $entryB->matchTier);
        $this->assertSame(3, $entryC->matchTier);
        $this->assertSame(1, $entryD->matchTier);

        $this->assertTrue($entryA->won);
        $this->assertTrue($entryB->won);
        $this->assertTrue($entryC->won); // sole entry in the 3-tier — takes its whole budget
        $this->assertFalse($entryD->won);
        $this->assertSame(0, $entryD->payoutKobo);

        // Net pool = (10,000+30,000+20,000+15,000) * 0.85 = 63,750.
        // 5-tier budget = 63,750 * 0.50 = 31,875 -> A:B = 10,000:30,000 = 25%:75%.
        $totalStake5Tier = 10_000 + 30_000;
        $expectedShareA = 10_000 / $totalStake5Tier;
        $this->assertEqualsWithDelta($expectedShareA, $entryA->payoutKobo / ($entryA->payoutKobo + $entryB->payoutKobo), 0.01);
        $this->assertGreaterThan(0, $entryA->payoutKobo);
        $this->assertGreaterThan($entryA->payoutKobo, $entryB->payoutKobo);

        // C is the pool's sole 3-tier entrant, so it takes the entire 3-tier budget:
        // 63,750 * 0.15 = 9,562 (intdiv truncation).
        $this->assertSame(intdiv(63_750 * 1_500, 10_000), $entryC->payoutKobo);

        foreach ([$ticketA, $ticketB, $ticketC, $ticketD] as $ticket) {
            $this->assertSame('SETTLED', $ticket->fresh()->status);
            $this->assertNotNull($ticket->fresh()->outcome);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);

        $this->assertSame(1, PoolDraw::where('gameCode', 'HERITAGE')->where('status', 'open')->count());
    }

    public function test_an_unwon_tier_rolls_its_budget_into_that_same_tiers_next_draw(): void
    {
        [, $token] = $this->signedInPlayer('+2348030003005');

        // Only a 2-tier entrant this draw — the 5/4/3 tiers all go unwon.
        $this->withToken($token)->postJson('/v1/heritage/tickets', ['selected_positions' => [0, 1, 5, 6, 7], 'stake_kobo' => 10_000, 'idempotency_key' => 'hg-settle-e'])->assertOk();

        $pool = PoolDraw::where('gameCode', 'HERITAGE')->sole();
        Http::fake(['*/engine/v1/draw-pool' => Http::response([
            'winning_positions' => [0, 1, 2, 3, 4],
            'engine_version' => 'heritage-1.0.0',
        ])]);

        app(HeritagePoolSettlementService::class)->settle($pool, $this->params);

        $settledPool = $pool->fresh();
        $this->assertSame('settled', $settledPool->status);

        $nextPool = PoolDraw::where('gameCode', 'HERITAGE')->where('id', '!=', $pool->id)->sole();
        // Net pool = 10,000 * 0.85 = 8,500. 5-tier budget = 4,250, 4-tier = 2,550,
        // 3-tier = 1,275, all unwon -> all roll forward; 2-tier (500bp) budget = 425,
        // won by the sole entrant -> tier 2 rollover is 0.
        $this->assertSame(4_250, $nextPool->tierRolloverJson['5']);
        $this->assertSame(2_550, $nextPool->tierRolloverJson['4']);
        $this->assertSame(1_275, $nextPool->tierRolloverJson['3']);
        $this->assertSame(0, $nextPool->tierRolloverJson['2']);
    }

    public function test_settling_a_pool_with_no_entries_produces_no_payouts_and_no_ledger_activity(): void
    {
        $pool = app(PoolDrawService::class)->openPool('HERITAGE', null, 60, 0, null);
        Http::fake(['*/engine/v1/draw-pool' => Http::response([
            'winning_positions' => [0, 1, 2, 3, 4],
            'engine_version' => 'heritage-1.0.0',
        ])]);

        $result = app(HeritagePoolSettlementService::class)->settle($pool, $this->params);

        $this->assertTrue($result);
        $this->assertSame('settled', $pool->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_pool_already_settled_by_a_concurrent_run_is_not_settled_twice(): void
    {
        $pool = app(PoolDrawService::class)->openPool('HERITAGE', null, 60, 0, null);
        PoolDraw::where('id', $pool->id)->update(['status' => 'closed']);

        $result = app(HeritagePoolSettlementService::class)->settle($pool, $this->params);

        $this->assertFalse($result);
    }
}
