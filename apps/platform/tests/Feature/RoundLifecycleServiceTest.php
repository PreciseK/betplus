<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\BirdEscape\RoundLifecycleService;
use App\Domain\Wallet\WalletService;
use App\Models\CrashBet;
use App\Models\CrashConfig;
use App\Models\CrashRound;
use App\Models\Player;
use Database\Seeders\BirdEscapeGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Drives RoundLifecycleService directly — no HTTP, no loop process — using
 * Carbon::setTestNow() to fast-forward and hand-crafted rounds with a known crash
 * point, so these tests are deterministic regardless of the engine's real RNG (that's
 * covered separately in BirdEscapeEngineTest).
 */
final class RoundLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BirdEscapeGameSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function player(): Player
    {
        return Player::create([
            'msisdn' => '+2348033' . random_int(100000, 999999),
            'registeredName' => 'Round Test Player', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', (string) random_int(1, 999999999)),
        ]);
    }

    private function placedBet(int $roundId, int $stakeKobo, ?int $autoCashoutMultiplierHundredths = null): CrashBet
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, $stakeKobo, 'collection', $player->id);

        $bet = CrashBet::create([
            'roundId' => $roundId,
            'playerId' => $player->id,
            'idempotencyKey' => 'bet-' . uniqid('', true),
            'stateCode' => 'LAG',
            'stakeKobo' => $stakeKobo,
            'autoCashoutMultiplierHundredths' => $autoCashoutMultiplierHundredths,
            'status' => 'PLACED',
        ]);

        app(WalletService::class)->reserveStake($player, $stakeKobo, 'crash_bet', $bet->id, 'LAG');

        return $bet;
    }

    /** A FLYING round with a known crash point and growth rate, bypassing the real RNG for deterministic tests. */
    private function craftFlyingRound(int $crashMultiplierHundredths, int $growthRateConstant, ?Carbon $flightStartedAt = null): CrashRound
    {
        $config = CrashConfig::where('gameCode', 'BIRDESCAPE')->where('status', 'published')->firstOrFail();
        $seed = app(SeedIssuer::class)->issue();

        return CrashRound::create([
            'gameCode' => 'BIRDESCAPE',
            'roundNumber' => 1 + (int) (CrashRound::where('gameCode', 'BIRDESCAPE')->max('roundNumber') ?? 0),
            'status' => 'FLYING',
            'fairnessSeedId' => $seed->id,
            'rngAlgorithm' => $seed->algorithm,
            'crashConfigId' => $config->id,
            'houseEdgeBasisPoints' => $config->houseEdgeBasisPoints,
            'bettingWindowSeconds' => $config->bettingWindowSeconds,
            'postCrashIntervalSeconds' => $config->postCrashIntervalSeconds,
            'growthRateConstant' => $growthRateConstant,
            'crashMultiplierHundredths' => $crashMultiplierHundredths,
            'commitmentDigest' => 'test-digest',
            'bettingStartedAt' => now()->subSeconds(10),
            'flightStartedAt' => $flightStartedAt ?? now()->subSeconds(5),
            'engineVersion' => 'birdescape-1.0.0',
        ]);
    }

    public function test_a_round_transitions_from_betting_to_flying_after_the_betting_window(): void
    {
        $lifecycle = app(RoundLifecycleService::class);
        $round = $lifecycle->startRound();
        $this->assertSame('BETTING', $round->status);

        Carbon::setTestNow(now()->addSeconds($round->bettingWindowSeconds + 1));
        $round = $lifecycle->advanceIfDue($round);

        $this->assertSame('FLYING', $round->status);
        $this->assertNotNull($round->flightStartedAt);
    }

    public function test_a_round_still_within_its_betting_window_does_not_transition(): void
    {
        $lifecycle = app(RoundLifecycleService::class);
        $round = $lifecycle->startRound();

        $round = $lifecycle->advanceIfDue($round);

        $this->assertSame('BETTING', $round->status);
    }

    public function test_flying_transitions_to_crashed_once_the_curve_crosses_the_crash_point(): void
    {
        // growthRateConstant=1 with 5s already elapsed means the curve is already far
        // past any 2.00x crash point by the time this tick runs.
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);

        $round = app(RoundLifecycleService::class)->advanceIfDue($round);

        $this->assertSame('CRASHED', $round->status);
        $this->assertNotNull($round->crashedAt);
    }

    public function test_an_auto_cashout_below_the_crash_point_settles_as_a_win_in_the_same_tick_the_round_crashes(): void
    {
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $bet = $this->placedBet($round->id, stakeKobo: 100_000, autoCashoutMultiplierHundredths: 150);

        app(RoundLifecycleService::class)->advanceIfDue($round);
        $bet->refresh();

        $this->assertSame('CASHED_OUT', $bet->status);
        $this->assertTrue($bet->autoCashedOut);
        $this->assertSame(150, $bet->cashedOutAtMultiplierHundredths);
        $this->assertSame(150_000, $bet->grossPrizeKobo); // 100_000 * 1.50
    }

    public function test_a_bet_with_no_auto_cashout_is_batch_settled_as_a_loss_on_crash(): void
    {
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $bet = $this->placedBet($round->id, stakeKobo: 100_000);

        app(RoundLifecycleService::class)->advanceIfDue($round);
        $bet->refresh();

        $this->assertSame('LOST', $bet->status);
        $this->assertFalse($bet->autoCashedOut);
    }

    public function test_an_auto_cashout_at_or_above_the_crash_point_is_never_settled_as_a_win(): void
    {
        // Threshold (250) is above the crash point (200) — the round crashes first,
        // so this bet must be batch-settled as a loss, never swept as a win.
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $bet = $this->placedBet($round->id, stakeKobo: 100_000, autoCashoutMultiplierHundredths: 250);

        app(RoundLifecycleService::class)->advanceIfDue($round);
        $bet->refresh();

        $this->assertSame('LOST', $bet->status);
    }

    public function test_the_ledger_stays_balanced_across_a_full_round_of_mixed_outcomes(): void
    {
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $this->placedBet($round->id, stakeKobo: 100_000, autoCashoutMultiplierHundredths: 150);
        $this->placedBet($round->id, stakeKobo: 50_000);
        $this->placedBet($round->id, stakeKobo: 75_000, autoCashoutMultiplierHundredths: 300);

        app(RoundLifecycleService::class)->advanceIfDue($round);

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();

        $this->assertSame($totals->debits, $totals->credits);
    }

    public function test_a_second_tick_on_an_already_crashed_round_does_not_reprocess_it(): void
    {
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $bet = $this->placedBet($round->id, stakeKobo: 100_000);

        $round = app(RoundLifecycleService::class)->advanceIfDue($round);
        $this->assertSame('CRASHED', $round->status);
        app(RoundLifecycleService::class)->advanceIfDue($round); // second tick — status='CRASHED' hits the no-op default arm

        $bet->refresh();
        $this->assertSame('LOST', $bet->status);
        // 2 lines from the original reserveStake (placedBet()'s setup) + 2 lines from
        // exactly one settleLoss (4 total) — proves advanceIfDue's status match, not
        // just luck, is what stopped a second processing attempt.
        $this->assertSame(
            4,
            DB::table('ledgerEntry')->where('referenceType', 'crash_bet')->where('referenceId', $bet->id)->count(),
        );
    }

    /**
     * The genuine race-guard test: two callers invoking settlement for the same bet
     * back-to-back (a double-click, a client retry, the loop's sweep and a manual
     * cashout landing at once) must settle it exactly once — this is
     * BirdEscapeSettlement's conditional `UPDATE ... WHERE status='PLACED'` guard,
     * the actual primitive the crash-vs-cashout race safety rests on. PHPUnit/sqlite
     * is single-connection and cannot fork true parallel requests, so this proves the
     * guard clause is correct, not that a true concurrent race is impossible under
     * real multi-connection load — that needs a k6/artillery-style test, flagged as a
     * follow-up in the implementation plan.
     */
    public function test_settling_the_same_bet_twice_back_to_back_only_applies_once(): void
    {
        $round = $this->craftFlyingRound(crashMultiplierHundredths: 200, growthRateConstant: 1);
        $bet = $this->placedBet($round->id, stakeKobo: 100_000);

        $settlement = app(\App\Domain\Games\BirdEscape\BirdEscapeSettlement::class);
        $first = $settlement->settleLoss($bet);
        $second = $settlement->settleLoss($bet->fresh());

        $this->assertTrue($first);
        $this->assertFalse($second, 'A second settlement attempt on an already-settled bet must be a no-op, not a double-payout.');
        $this->assertSame(
            4,
            DB::table('ledgerEntry')->where('referenceType', 'crash_bet')->where('referenceId', $bet->id)->count(),
        );
    }
}
