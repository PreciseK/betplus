<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Ticket\CreateTicket;
use App\Domain\Ticket\RevealTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\TurnoverService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseTicketRequest;
use App\Models\GameRegistry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class BlackRedController extends Controller
{
    /** No real geolocation vendor is contracted (see StubLocationSignalProvider); Lagos is the only seeded state. */
    private const STATE_NAMES = ['LAG' => 'Lagos'];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CreateTicket $createTicket,
        private readonly RevealTicket $revealTicket,
        private readonly PrizeTableResolver $prizeTableResolver,
        private readonly TurnoverService $turnover,
    ) {
    }

    /** GET /v1/games/blackred */
    public function show(): JsonResponse
    {
        $player = $this->player();
        $game = GameRegistry::where('gameCode', 'BLACKRED')->firstOrFail();
        $wallet = $this->wallet->walletFor($player);
        $stateCode = (string) config('jurisdiction.stub_state_code');

        $prizeTable = $this->prizeTableResolver->resolveFor('BLACKRED', $stateCode);
        if ($prizeTable === null) {
            return response()->json(['message' => 'BlackRed is not currently available.'], 503);
        }

        $turnover = $this->turnover->positionFor($player);
        $dailyLimitKobo = (int) config('game.default_daily_limit_kobo');
        $stakedTodayKobo = $this->stakedTodayFor($player);

        return response()->json([
            'game_name' => 'BlackRed',
            'description' => 'Instant fixed-odds prediction',
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            'turnover_staked_kobo' => $turnover['stakedKobo'],
            'turnover_required_kobo' => $turnover['requiredKobo'],
            'daily_limit_kobo' => $dailyLimitKobo,
            'daily_limit_remaining_kobo' => max(0, $dailyLimitKobo - $stakedTodayKobo),
            'min_stake_kobo' => $game->minStakeKobo,
            'max_stake_kobo' => $game->maxStakeKobo,
            'currency' => 'NGN',
            'state_name' => self::STATE_NAMES[$stateCode] ?? $stateCode,
            'tax_rate_basis_points' => (int) config('tax.withholding.resident_rate_basis_points'),
            'tax_basis_label' => (string) config('tax.withholding.basis_label'),
            'ruleset_version' => (string) config('jurisdiction.ruleset_version'),
            'prize_table_version' => $prizeTable->version,
            'engine_version' => $game->engineVersion,
            'tiers' => $prizeTable->tiers->map(fn ($tier) => [
                'positions' => $tier->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ])->values(),
        ]);
    }

    /** POST /v1/tickets — REQ-TKT-001/005: the response discloses nothing about the outcome. */
    public function purchase(PurchaseTicketRequest $request): JsonResponse
    {
        $player = $this->player();

        try {
            $ticket = $this->createTicket->create(
                $player,
                $request->input('prediction'),
                (int) $request->input('stake_kobo'),
                $request->string('idempotency_key')->toString(),
            );
        } catch (TicketEligibilityException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        $wallet = $this->wallet->walletFor($player);

        // Model 4 — no outcome to withhold; there isn't one yet.
        if ($ticket->status === 'PENDING_DRAW') {
            return response()->json([
                'reference' => $ticket->reference,
                'purchased_at' => $ticket->createdAt->toIso8601String(),
                'prediction' => $ticket->predictionJson,
                'stake_kobo' => $ticket->stakeKobo,
                'play_balance_after_kobo' => $wallet->playBalanceKobo,
                'status' => 'pending_draw',
                'draw_at' => $ticket->poolEntry?->poolDraw?->closesAt?->toIso8601String(),
            ]);
        }

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'prediction' => $ticket->predictionJson,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'purchased',
        ]);
    }

    /** GET /v1/tickets/{reference}/reveal */
    public function reveal(string $reference): JsonResponse
    {
        $player = $this->player();
        $ticket = $this->revealTicket->reveal($player, $reference);
        if ($ticket === null) {
            return response()->json(['message' => 'Ticket not found or not yet settled.'], 404);
        }

        if ($ticket->status === 'PENDING_DRAW') {
            return response()->json([
                'reference' => $ticket->reference,
                'status' => 'pending_draw',
                'draw_at' => $ticket->poolEntry?->poolDraw?->closesAt?->toIso8601String(),
            ]);
        }

        $outcome = $ticket->outcome;
        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'prediction' => $ticket->predictionJson,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'settled',
            'result' => $outcome->resultJson,
            'won' => $outcome->won,
            'gross_prize_kobo' => $outcome->grossPrizeKobo,
            'tax_withheld_kobo' => $outcome->taxWithheldKobo,
            'net_credit_kobo' => $outcome->netCreditKobo,
            'winnings_balance_after_kobo' => $wallet->winningsBalanceKobo,
            'tax_rate_basis_points' => $outcome->taxRateBasisPoints,
            'tax_basis_label' => $outcome->taxBasisLabel,
            'ruleset_version' => $ticket->jurisdictionRulesetVersion,
            'prize_table_version' => $ticket->prizeTableVersion,
            'engine_version' => $ticket->engineVersion,
            'state_name' => self::STATE_NAMES[$ticket->stateCode] ?? $ticket->stateCode,
        ]);
    }

    /** @return array{positions:int,multiplier_hundredths:int,probability_numerator:int,probability_denominator:int} */
    private function tierShape(Ticket $ticket): array
    {
        $prizeTable = $this->prizeTableResolver->resolveFor($ticket->gameCode, $ticket->stateCode, $ticket->createdAt);
        $tier = $prizeTable?->tiers->firstWhere('positions', $ticket->positions);

        if ($tier !== null) {
            return [
                'positions' => $ticket->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ];
        }

        return [
            'positions' => $ticket->positions,
            'multiplier_hundredths' => 0,
            'probability_numerator' => 0,
            'probability_denominator' => 1,
        ];
    }

    /** Real platform default (config), not a player-set RG limit — Epic 5 territory. */
    private function stakedTodayFor(Player $player): int
    {
        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        if ($account === null) {
            return 0;
        }

        return (int) LedgerEntry::where('accountId', $account->id)
            ->where('direction', 'debit')->where('referenceType', 'ticket')
            ->where('createdAt', '>=', now()->startOfDay())
            ->sum('amountKobo');
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
