<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Ticket\CreateCagedTicket;
use App\Domain\Ticket\RevealTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\TurnoverService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseCagedTicketRequest;
use App\Models\GameRegistry;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class CagedController extends Controller
{
    private const STATE_NAMES = ['LAG' => 'Lagos'];
    private const GAME_CODE = 'CAGED';

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CreateCagedTicket $createTicket,
        private readonly RevealTicket $revealTicket,
        private readonly PrizeTableResolver $prizeTableResolver,
        private readonly TurnoverService $turnover,
    ) {
    }

    /** GET /v1/games/caged */
    public function show(): JsonResponse
    {
        $player = $this->player();
        $game = GameRegistry::where('gameCode', self::GAME_CODE)->firstOrFail();
        $wallet = $this->wallet->walletFor($player);
        $stateCode = (string) config('jurisdiction.stub_state_code');

        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, $stateCode);
        if ($prizeTable === null) {
            return response()->json(['message' => 'Caged is not currently available.'], 503);
        }

        $turnover = $this->turnover->positionFor($player);
        $dailyLimitKobo = (int) config('game.default_daily_limit_kobo');
        $stakedTodayKobo = $this->stakedTodayFor($player);

        return response()->json([
            'game_name' => 'Caged',
            'description' => 'Predict how many birds escape before the cage slams shut.',
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
                'target_birds' => $tier->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ])->values(),
        ]);
    }

    /** POST /v1/caged/tickets — the response discloses nothing about the outcome. */
    public function purchase(PurchaseCagedTicketRequest $request): JsonResponse
    {
        $player = $this->player();

        try {
            $ticket = $this->createTicket->create(
                $player,
                (int) $request->input('target_birds'),
                (int) $request->input('stake_kobo'),
                $request->string('idempotency_key')->toString(),
            );
        } catch (TicketEligibilityException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'target_birds' => $ticket->positions,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'purchased',
        ]);
    }

    /** GET /v1/caged/tickets/{reference}/reveal */
    public function reveal(string $reference): JsonResponse
    {
        $player = $this->player();
        $ticket = $this->revealTicket->reveal($player, $reference);
        if ($ticket === null) {
            return response()->json(['message' => 'Ticket not found or not yet settled.'], 404);
        }

        $outcome = $ticket->outcome;
        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'target_birds' => $ticket->positions,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'settled',
            'escaped_birds' => $outcome->resultJson['escaped_birds'],
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

    /** @return array{target_birds:int,multiplier_hundredths:int,probability_numerator:int,probability_denominator:int} */
    private function tierShape(Ticket $ticket): array
    {
        $prizeTable = $this->prizeTableResolver->resolveFor($ticket->gameCode, $ticket->stateCode, $ticket->createdAt);
        $tier = $prizeTable?->tiers->firstWhere('positions', $ticket->positions);

        if ($tier !== null) {
            return [
                'target_birds' => $ticket->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ];
        }

        return [
            'target_birds' => $ticket->positions,
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
