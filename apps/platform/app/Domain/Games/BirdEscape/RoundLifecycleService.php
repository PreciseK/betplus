<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use App\Models\CrashBet;
use App\Models\CrashRound;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Drives one shared round through BETTING -> FLYING -> CRASHED and back to a fresh
 * BETTING round, on behalf of both the supervised RoundLoopCommand and, directly,
 * anything that needs to force a tick in a test (Carbon::setTestNow() + advanceIfDue()
 * with no HTTP and no loop process involved).
 *
 * The correctness-critical guarantee: crashMultiplierHundredths, flightStartedAt and
 * growthRateConstant are write-once on a round (set in startRound(), never mutated
 * again), so every reader — a loop tick or a player's cashout request arriving at the
 * exact same instant — computes off identical, stable values with no lock needed to
 * read them. The only genuine race is many writers touching one CrashBet row at once,
 * and that's closed in BirdEscapeSettlement's conditional UPDATE, not here.
 */
final class RoundLifecycleService
{
    public function __construct(
        private readonly SeedIssuer $seedIssuer,
        private readonly CrashConfigResolver $configs,
        private readonly BirdEscapeEngine $engine,
        private readonly BirdEscapeSettlement $settlement,
    ) {
    }

    public function currentOrNextRound(string $gameCode = 'BIRDESCAPE'): CrashRound
    {
        $latest = CrashRound::where('gameCode', $gameCode)->orderByDesc('id')->first();

        if ($latest === null) {
            return $this->startRound($gameCode);
        }

        if ($latest->status === 'CRASHED' && $latest->crashedAt->addSeconds($latest->postCrashIntervalSeconds)->isPast()) {
            return $this->startRound($gameCode);
        }

        return $this->advanceIfDue($latest);
    }

    public function startRound(string $gameCode = 'BIRDESCAPE'): CrashRound
    {
        $config = $this->configs->resolveFor($gameCode);
        if ($config === null) {
            throw new RuntimeException("No published crash config for $gameCode.");
        }

        $seed = $this->seedIssuer->issue();

        // Enforce 24-hour rolling tier limits:
        // - Max 2 rounds > 25.00x (up to 35.00x)
        // - Max 5 rounds between 20.00x - 25.00x
        // - Max 10 rounds between 15.00x - 20.00x
        $since = now()->subHours(24);
        $stats = CrashRound::where('gameCode', $gameCode)
            ->where('createdAt', '>=', $since)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN crashMultiplierHundredths > 2500 THEN 1 ELSE 0 END), 0) as above25,
                COALESCE(SUM(CASE WHEN crashMultiplierHundredths >= 2000 AND crashMultiplierHundredths <= 2500 THEN 1 ELSE 0 END), 0) as bet20_25,
                COALESCE(SUM(CASE WHEN crashMultiplierHundredths >= 1500 AND crashMultiplierHundredths < 2000 THEN 1 ELSE 0 END), 0) as bet15_20
            ')->first();

        $dailyAbove25x = (int) ($stats->above25 ?? 0);
        $dailyBetween20xAnd25x = (int) ($stats->bet20_25 ?? 0);
        $dailyBetween15xAnd20x = (int) ($stats->bet15_20 ?? 0);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $roundNumber = 1 + (int) (CrashRound::where('gameCode', $gameCode)->max('roundNumber') ?? 0);
            $engineResult = $this->engine->resolve(
                $seed->seedHex,
                $roundNumber,
                $config->houseEdgeBasisPoints,
                $dailyAbove25x,
                $dailyBetween20xAnd25x,
                $dailyBetween15xAnd20x
            );

            try {
                return CrashRound::create([
                    'gameCode' => $gameCode,
                    'roundNumber' => $roundNumber,
                    'status' => 'BETTING',
                    'fairnessSeedId' => $seed->id,
                    'rngAlgorithm' => $seed->algorithm,
                    'crashConfigId' => $config->id,
                    'houseEdgeBasisPoints' => $config->houseEdgeBasisPoints,
                    'bettingWindowSeconds' => $config->bettingWindowSeconds,
                    'postCrashIntervalSeconds' => $config->postCrashIntervalSeconds,
                    'growthRateConstant' => $config->growthRateConstant,
                    'crashMultiplierHundredths' => $engineResult->crashMultiplierHundredths,
                    'commitmentDigest' => $engineResult->digest,
                    'bettingStartedAt' => now(),
                    'engineVersion' => $engineResult->engineVersion,
                ]);
            } catch (QueryException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
                // Unique (gameCode, roundNumber) violation — another process created
                // the same next round first; loop and recompute against fresh state.
            }
        }

        throw new RuntimeException('Could not create a new round after 3 concurrent-write retries.');
    }

    /** Called repeatedly (by the loop, or directly by a test) for the live round. */
    public function advanceIfDue(CrashRound $round): CrashRound
    {
        return match ($round->status) {
            'BETTING' => $this->maybeStartFlight($round),
            'FLYING' => $this->tickFlight($round),
            default => $round,
        };
    }

    private function maybeStartFlight(CrashRound $round): CrashRound
    {
        if ($round->bettingStartedAt->addSeconds($round->bettingWindowSeconds)->isFuture()) {
            return $round;
        }

        DB::transaction(function () use ($round) {
            CrashRound::where('id', $round->id)->where('status', 'BETTING')
                ->update(['status' => 'FLYING', 'flightStartedAt' => now()]);
        });

        return $round->refresh();
    }

    private function tickFlight(CrashRound $round): CrashRound
    {
        $elapsedMs = (int) $round->flightStartedAt->diffInMilliseconds(now());
        $currentMultiplier = BirdEscapeEngine::multiplierHundredthsAtElapsedMs($elapsedMs, $round->growthRateConstant);

        // 1. Auto-cashout sweep first — a bet whose threshold has already been crossed
        //    must settle as a win even in the same tick the round crashes.
        CrashBet::where('roundId', $round->id)->where('status', 'PLACED')
            ->whereNotNull('autoCashoutMultiplierHundredths')
            ->where('autoCashoutMultiplierHundredths', '<=', $currentMultiplier)
            ->where('autoCashoutMultiplierHundredths', '<', $round->crashMultiplierHundredths)
            ->get()
            ->each(fn (CrashBet $bet) => $this->settlement->settleCashout($bet, $bet->autoCashoutMultiplierHundredths, auto: true));

        if ($currentMultiplier < $round->crashMultiplierHundredths) {
            return $round; // still flying
        }

        // 2. Crash detection — a conditional UPDATE guards double-crash-processing
        //    (an overlapping tick, or two loop instances running by operator mistake).
        $wonTransition = DB::transaction(function () use ($round) {
            return CrashRound::where('id', $round->id)->where('status', 'FLYING')
                ->update(['status' => 'CRASHED', 'crashedAt' => now()]) === 1;
        });

        if ($wonTransition) {
            // 3. Batch-settle every remaining PLACED bet as a loss, in the same moment
            //    the round is marked CRASHED — never lazily, never on next request.
            CrashBet::where('roundId', $round->id)->where('status', 'PLACED')->get()
                ->each(fn (CrashBet $bet) => $this->settlement->settleLoss($bet));
        }

        return $round->refresh();
    }
}
