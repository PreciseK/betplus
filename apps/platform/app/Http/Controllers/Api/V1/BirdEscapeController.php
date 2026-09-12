<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\BirdEscape\BirdEscapeSettlement;
use App\Domain\Games\BirdEscape\PlaceCrashBet;
use App\Domain\Games\BirdEscape\RoundLifecycleService;
use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlaceCrashBetRequest;
use App\Models\CrashBet;
use App\Models\CrashRound;
use App\Models\GameRegistry;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BirdEscapeController extends Controller
{
    private const GAME_CODE = 'BIRDESCAPE';
    private const RECENT_ROUNDS_LIMIT = 25;
    private static ?GameRegistry $cachedGame = null;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly RoundLifecycleService $lifecycle,
        private readonly PlaceCrashBet $placeCrashBet,
        private readonly BirdEscapeSettlement $settlement,
    ) {
    }

    /**
     * GET /v1/birdescape/rounds/current — REQ-TKT-005-style non-disclosure: the crash
     * multiplier and seed are only ever present in the response once status=CRASHED.
     * That boundary is the actual secrecy guarantee of the whole game, enforced here.
     */
    public function currentRound(): JsonResponse
    {
        $player = $this->player();
        $game = self::$cachedGame ??= GameRegistry::where('gameCode', self::GAME_CODE)->firstOrFail();
        $round = $this->lifecycle->currentOrNextRound(self::GAME_CODE);
        $wallet = $this->wallet->walletFor($player);

        $revealed = $round->status === 'CRASHED';
        $seedHex = null;
        if ($revealed) {
            $seedHex = $round->fairnessSeed?->seedHex;
        }

        return response()->json([
            'game_code' => self::GAME_CODE,
            'server_time' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'round_id' => $round->id,
            'round_number' => $round->roundNumber,
            'status' => $round->status,
            'betting_started_at' => $round->bettingStartedAt->format('Y-m-d\TH:i:s.v\Z'),
            'flight_started_at' => $round->flightStartedAt?->format('Y-m-d\TH:i:s.v\Z'),
            'betting_window_seconds' => $round->bettingWindowSeconds,
            'growth_rate_constant' => $round->growthRateConstant,
            'commitment_digest' => $round->commitmentDigest,
            'min_stake_kobo' => $game->minStakeKobo,
            'max_stake_kobo' => $game->maxStakeKobo,
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            // Never present before CRASHED — this is the secrecy boundary, not the
            // commitment_digest above (which is public from round start by design).
            'crash_multiplier_hundredths' => $revealed ? $round->crashMultiplierHundredths : null,
            'seed_hex' => $seedHex,
            'recent_rounds' => $this->recentRounds(),
            'players' => $this->anonymizedPlayers($round, $player),
            'my_bets' => $this->myBets($round, $player),
        ]);
    }

    /** POST /v1/birdescape/rounds/{round}/bets */
    public function placeBet(PlaceCrashBetRequest $request, CrashRound $round): JsonResponse
    {
        $player = $this->player();

        try {
            $bet = $this->placeCrashBet->place(
                $player,
                $round,
                (int) $request->input('stake_kobo'),
                $request->input('auto_cashout_multiplier_hundredths') !== null ? (int) $request->input('auto_cashout_multiplier_hundredths') : null,
                $request->string('idempotency_key')->toString(),
            );
        } catch (TicketEligibilityException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'bet_id' => $bet->id,
            'round_number' => $round->roundNumber,
            'stake_kobo' => $bet->stakeKobo,
            'auto_cashout_multiplier_hundredths' => $bet->autoCashoutMultiplierHundredths,
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'placed',
        ]);
    }

    /**
     * POST /v1/birdescape/bets/{bet}/cashout — the authoritative gate. Recomputes
     * elapsed time and the multiplier fresh, from scratch, on every call — never
     * trusts the round's cached status — so this is correct even mid-transition or if
     * the supervised round loop has stalled entirely.
     */
    public function cashout(CrashBet $bet): JsonResponse
    {
        $player = $this->player();
        if ($bet->playerId !== $player->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $round = $bet->round;
        if ($round->status === 'BETTING' || $round->flightStartedAt === null) {
            return response()->json(['code' => 'ROUND_NOT_FLYING', 'message' => 'This round has not started flying yet.'], 422);
        }

        if ($bet->status !== 'PLACED') {
            // Already settled (win, loss, or a concurrent cashout that won the race) —
            // idempotent replay of the real outcome, never an error.
            return response()->json($this->betShape($bet));
        }

        $elapsedMs = (int) $round->flightStartedAt->diffInMilliseconds(now());
        $requestMultiplier = BirdEscapeEngine::multiplierHundredthsAtElapsedMs($elapsedMs, $round->growthRateConstant);

        if ($requestMultiplier >= $round->crashMultiplierHundredths || $round->status === 'CRASHED') {
            // Too late — the round should already be crashed even if nothing has
            // ticked it yet. Drive the SAME lifecycle transition the round loop uses
            // (it batch-settles every other still-PLACED bet in this round, not just
            // this one) rather than hand-rolling a partial crash here. Hand-rolling it
            // (marking only the round crashed and settling only this bet) is exactly
            // the bug that used to live here: every OTHER player's bet in the round
            // would be left stuck in PLACED — stake money never released from SUSPENSE
            // — until something else happened to notice.
            $this->lifecycle->advanceIfDue($round);
            $bet->refresh();
            if ($bet->status === 'PLACED') {
                // Defensive only — advanceIfDue's batch-loss sweep should already have
                // settled this bet. Never leave one stuck in PLACED after its round crashed.
                $this->settlement->settleLoss($bet);
                $bet->refresh();
            }
            return response()->json($this->betShape($bet));
        }

        $this->settlement->settleCashout($bet, $requestMultiplier, auto: false);

        return response()->json($this->betShape($bet->refresh()));
    }

    /** @return array<string, mixed> */
    private function betShape(CrashBet $bet): array
    {
        $wallet = $this->wallet->walletFor($bet->player);

        return [
            'bet_id' => $bet->id,
            'status' => $bet->status,
            'cashed_out_at_multiplier_hundredths' => $bet->cashedOutAtMultiplierHundredths,
            'gross_prize_kobo' => $bet->grossPrizeKobo,
            'tax_withheld_kobo' => $bet->taxWithheldKobo,
            'net_credit_kobo' => $bet->netCreditKobo,
            'winnings_balance_after_kobo' => $wallet->winningsBalanceKobo,
        ];
    }

    /** @return list<array{round_number:int, crash_multiplier_hundredths:int, crashed_at:string}> */
    private function recentRounds(): array
    {
        return Cache::remember('birdescape_recent_rounds', 3, function () {
            return CrashRound::where('gameCode', self::GAME_CODE)
                ->where('status', 'CRASHED')
                ->orderByDesc('roundNumber')
                ->limit(self::RECENT_ROUNDS_LIMIT)
                ->get()
                ->map(fn (CrashRound $r) => [
                    'round_number' => $r->roundNumber,
                    'crash_multiplier_hundredths' => $r->crashMultiplierHundredths,
                    'crashed_at' => $r->crashedAt->toIso8601String(),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * No identifying information at all — this codebase has no public username field
     * on Player (identity is MSISDN-based), so "anonymized" here means genuinely
     * anonymous rather than a pseudonym invented for this endpoint.
     *
     * @return list<array{stake_kobo:int, status:string, cashed_out_at_multiplier_hundredths:?int}>
     */
    private function anonymizedPlayers(CrashRound $round, Player $viewer): array
    {
        return CrashBet::where('roundId', $round->id)
            ->where('playerId', '!=', $viewer->id)
            ->get()
            ->map(fn (CrashBet $b) => [
                'stake_kobo' => $b->stakeKobo,
                'status' => $b->status,
                'cashed_out_at_multiplier_hundredths' => $b->cashedOutAtMultiplierHundredths,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function myBets(CrashRound $round, Player $player): array
    {
        return CrashBet::where('roundId', $round->id)->where('playerId', $player->id)
            ->get()
            ->map(fn (CrashBet $b) => [
                'bet_id' => $b->id,
                'stake_kobo' => $b->stakeKobo,
                'auto_cashout_multiplier_hundredths' => $b->autoCashoutMultiplierHundredths,
                'status' => $b->status,
                'cashed_out_at_multiplier_hundredths' => $b->cashedOutAtMultiplierHundredths,
                'gross_prize_kobo' => $b->grossPrizeKobo,
                'tax_withheld_kobo' => $b->taxWithheldKobo,
                'net_credit_kobo' => $b->netCreditKobo,
            ])
            ->values()
            ->all();
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
