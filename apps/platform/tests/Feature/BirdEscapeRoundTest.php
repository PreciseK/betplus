<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\CrashBet;
use App\Models\CrashRound;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\BirdEscapeGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BirdEscapeRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BirdEscapeGameSeeder::class);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348034123456', int $fundedKobo = 1_000_000): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Bird Test Player', 'registrationChannel' => 'web', 'kycTier' => 1,
            'ninHash' => hash('sha256', $msisdn),
        ]);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
        $tokens = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json();

        if ($fundedKobo > 0) {
            app(WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);
        }

        return [$player, $tokens['access_token']];
    }

    /** Reads /rounds/current once (lazily creating the round if none exists) and returns the fresh model. */
    private function currentRound(string $token): CrashRound
    {
        $this->withToken($token)->getJson('/v1/birdescape/rounds/current');

        return CrashRound::where('gameCode', 'BIRDESCAPE')->firstOrFail();
    }

    public function test_current_round_never_discloses_the_crash_point_before_it_crashes(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->getJson('/v1/birdescape/rounds/current');

        $response->assertOk();
        $body = $response->json();
        $this->assertContains($body['status'], ['BETTING', 'FLYING']);
        $this->assertNull($body['crash_multiplier_hundredths']);
        $this->assertNull($body['seed_hex']);
        $this->assertArrayHasKey('commitment_digest', $body); // public from round start, unlike the two above
        $this->assertIsInt($body['round_id']); // the id the bet-placement route needs
    }

    public function test_current_round_discloses_the_crash_point_once_crashed(): void
    {
        [, $token] = $this->signedInPlayer();
        $round = $this->currentRound($token);
        $round->update(['status' => 'CRASHED', 'crashedAt' => now()]);

        $response = $this->withToken($token)->getJson('/v1/birdescape/rounds/current');

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('CRASHED', $body['status']);
        $this->assertSame($round->crashMultiplierHundredths, $body['crash_multiplier_hundredths']);
        $this->assertNotNull($body['seed_hex']);
    }

    public function test_placing_a_bet_reserves_the_stake_and_leaves_the_ledger_balanced(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);
        $round = $this->currentRound($token);

        $response = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 50_000,
            'idempotency_key' => 'bet-1',
        ]);

        $response->assertOk();
        $this->assertSame(450_000, $response->json('play_balance_after_kobo'));

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);
    }

    public function test_a_bet_is_refused_once_the_round_is_no_longer_betting(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);
        $round = $this->currentRound($token);
        $round->update(['status' => 'FLYING', 'flightStartedAt' => now()]);

        $response = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 50_000,
            'idempotency_key' => 'bet-refused',
        ]);

        $response->assertStatus(422);
        $this->assertSame('ROUND_NOT_ACCEPTING_BETS', $response->json('code'));
    }

    public function test_a_stake_exceeding_play_balance_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 5_000);
        $round = $this->currentRound($token);

        $response = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 100_000,
            'idempotency_key' => 'balance-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_PLAY_BALANCE', $response->json('code'));
        $this->assertSame(0, CrashBet::where('playerId', $player->id)->count());
        $this->assertSame(5_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }

    public function test_a_repeated_idempotency_key_returns_the_same_bet_without_double_charging(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 500_000);
        $round = $this->currentRound($token);

        $first = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 50_000, 'idempotency_key' => 'replay-key',
        ])->json();
        $second = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 50_000, 'idempotency_key' => 'replay-key',
        ])->json();

        $this->assertSame($first['bet_id'], $second['bet_id']);
        $this->assertSame(1, CrashBet::where('playerId', $player->id)->count());
    }

    public function test_cashing_out_before_the_crash_credits_winnings_net_of_withholding(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);
        $round = $this->currentRound($token);

        $bet = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 100_000, 'idempotency_key' => 'cashout-win',
        ])->json();

        // Force a known-safe window: crash point set far away so any instant right
        // after flight start sits well below it.
        $round->update([
            'status' => 'FLYING', 'flightStartedAt' => now(),
            'crashMultiplierHundredths' => 100_000, 'growthRateConstant' => 500_000,
        ]);

        $response = $this->withToken($token)->postJson("/v1/birdescape/bets/{$bet['bet_id']}/cashout");

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('CASHED_OUT', $body['status']);
        $this->assertGreaterThan(0, $body['gross_prize_kobo']);
        $this->assertSame($body['gross_prize_kobo'] - $body['tax_withheld_kobo'], $body['net_credit_kobo']);
    }

    public function test_cashing_out_after_the_crash_is_rejected_as_too_late(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);
        $round = $this->currentRound($token);

        $bet = $this->withToken($token)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 100_000, 'idempotency_key' => 'cashout-late',
        ])->json();

        // flightStartedAt far enough in the past, with a fast growth rate, that the
        // curve has already blown past a low crash multiplier by the time this runs.
        $round->update([
            'status' => 'FLYING', 'flightStartedAt' => now()->subSeconds(10),
            'crashMultiplierHundredths' => 150, 'growthRateConstant' => 1,
        ]);

        $response = $this->withToken($token)->postJson("/v1/birdescape/bets/{$bet['bet_id']}/cashout");

        // A too-late cashout settles the bet as a normal loss rather than erroring —
        // consistent with the "already settled" idempotent-replay branch just above
        // this one in the controller. This also drives the round's own crash
        // transition (via RoundLifecycleService::advanceIfDue) if nothing had ticked
        // it yet, batch-settling every other PLACED bet in the round too.
        $response->assertOk();
        $this->assertSame('LOST', $response->json('status'));
        $this->assertSame('CRASHED', $round->fresh()->status);
    }

    /**
     * Regression test for a real bug: cashout() used to mark only the round crashed
     * and settle only the calling player's bet, leaving every OTHER player's PLACED
     * bet in that round stuck forever (stake money never released from SUSPENSE).
     * The fix routes the crash transition through RoundLifecycleService::advanceIfDue,
     * which batch-settles every remaining bet — this proves that actually happens
     * when a cashout request is the first thing to notice the round has crashed.
     */
    public function test_a_cashout_that_discovers_the_round_has_crashed_also_settles_every_other_placed_bet(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348034333333', fundedKobo: 500_000);
        $round = $this->currentRound($tokenA);
        $betA = $this->withToken($tokenA)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 100_000, 'idempotency_key' => 'racer-a',
        ])->json();

        [, $tokenB] = $this->signedInPlayer('+2348034444444', fundedKobo: 500_000);
        $betB = $this->withToken($tokenB)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 75_000, 'idempotency_key' => 'racer-b',
        ])->json();

        // Force the round past its crash point without anything having ticked it yet
        // — reproduces exactly the scenario the bug lived in: a cashout request is
        // the FIRST thing to notice the round should already be crashed.
        $round->update([
            'status' => 'FLYING', 'flightStartedAt' => now()->subSeconds(10),
            'crashMultiplierHundredths' => 150, 'growthRateConstant' => 1,
        ]);

        $this->withToken($tokenA)->postJson("/v1/birdescape/bets/{$betA['bet_id']}/cashout")->assertOk();

        // Player B never touched the API again — their bet must still have been
        // settled by player A's cashout request discovering the crash, not left
        // stuck in PLACED with their stake still parked in SUSPENSE indefinitely.
        $this->assertSame('LOST', CrashBet::find($betB['bet_id'])->status);
        $this->assertSame(0, CrashBet::where('status', 'PLACED')->where('roundId', $round->id)->count());

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);
    }

    public function test_cashing_out_someone_elses_bet_is_refused(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348034111111', fundedKobo: 500_000);
        $round = $this->currentRound($tokenA);
        $bet = $this->withToken($tokenA)->postJson("/v1/birdescape/rounds/{$round->id}/bets", [
            'stake_kobo' => 50_000, 'idempotency_key' => 'owner-bet',
        ])->json();

        [, $tokenB] = $this->signedInPlayer('+2348034222222', fundedKobo: 500_000);
        $response = $this->withToken($tokenB)->postJson("/v1/birdescape/bets/{$bet['bet_id']}/cashout");

        $response->assertStatus(404);
    }
}
