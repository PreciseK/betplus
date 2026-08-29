<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\PrizeTable\HeritagePrizeTableResolver;
use App\Domain\Ticket\CreateHeritageTicket;
use App\Domain\Ticket\RevealTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseHeritageTicketRequest;
use App\Models\GameRegistry;
use App\Models\HeritageCatalogueItem;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class HeritageController extends Controller
{
    private const STATE_NAMES = ['LAG' => 'Lagos'];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CreateHeritageTicket $createTicket,
        private readonly RevealTicket $revealTicket,
        private readonly HeritagePrizeTableResolver $prizeTableResolver,
    ) {
    }

    /** GET /v1/games/heritage */
    public function show(): JsonResponse
    {
        $player = $this->player();
        $game = GameRegistry::where('gameCode', 'HERITAGE')->firstOrFail();
        $wallet = $this->wallet->walletFor($player);
        $stateCode = (string) config('jurisdiction.stub_state_code');

        $prizeTable = $this->prizeTableResolver->resolveFor('HERITAGE', $stateCode);
        if ($prizeTable === null) {
            return response()->json(['message' => 'Heritage is not currently available.'], 503);
        }

        return response()->json([
            'game_name' => 'Heritage',
            'description' => 'Dress your royal in regalia from across Nigeria.',
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            'min_stake_kobo' => $game->minStakeKobo,
            'max_stake_kobo' => $game->maxStakeKobo,
            'currency' => 'NGN',
            'state_name' => self::STATE_NAMES[$stateCode] ?? $stateCode,
            'board_size' => 9,
            'pick_size' => 5,
            'lowest_tier_label' => '1–2 matches', // REQ-HG-015 — "0 match" is never player-facing copy
            'prize_table_version' => $prizeTable->version,
            'engine_version' => $game->engineVersion,
            'tiers' => $prizeTable->heritageTiers->map(fn ($tier) => [
                'name' => $tier->tierName,
                'probability_basis_points' => $tier->probabilityBasisPoints,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'outcome_type' => $tier->outcomeType,
            ])->values(),
            'traditions' => collect((array) config('heritage.traditions'))->map(fn ($t, $code) => [
                'code' => $code,
                'label' => $t['label'],
                'king_title' => $t['king_title'],
                'queen_title' => $t['queen_title'],
            ])->values(),
            'current_tradition' => $player->heritageTraditionCode,
            'current_leader_type' => $player->heritageLeaderType,
        ]);
    }

    /**
     * GET /v1/heritage/catalogue — the static 1-90 regalia reference the frontend
     * joins against reveal()'s bare position numbers to render each board tile.
     * REQ-HG-064 — publicationStatus is computed, never asserted: an item is only
     * 'approved' once BOTH a real advisor sign-off reference AND a publish date
     * are on record. The seeder deliberately leaves every item at signOffRef=null
     * (see HeritageGameSeeder's own doc comment) — no cultural content in this
     * codebase claims advisor approval it doesn't have.
     */
    public function catalogue(): JsonResponse
    {
        $items = HeritageCatalogueItem::orderBy('itemNumber')->get()->map(fn (HeritageCatalogueItem $item) => [
            'number' => $item->itemNumber,
            'canonical_name' => $item->canonicalName,
            'local_name' => $item->localName,
            'origin' => $item->traditionOfOrigin,
            'context' => $item->culturalDescription,
            'slot' => $item->bodySlot,
            'depiction' => 'abstract-placeholder',
            'applicable_traditions' => [$item->traditionOfOrigin],
            'applicable_leaders' => match ($item->leaderApplicability) {
                'king' => ['king'],
                'queen' => ['queen'],
                default => ['king', 'queen'],
            },
            'layer_priority' => $item->layerPriority,
            'depiction_constraints' => $item->depictionConstraint ?? 'none',
            'advisor_sign_off_reference' => $item->signOffRef,
            'publication_status' => $item->signOffRef !== null && $item->publishedAt !== null ? 'approved' : 'preview-only',
        ]);

        return response()->json(['items' => $items]);
    }

    /** POST /v1/heritage/tickets — the response discloses nothing about the outcome (REQ-HG board is face-down until reveal). */
    public function purchase(PurchaseHeritageTicketRequest $request): JsonResponse
    {
        $player = $this->player();

        try {
            $ticket = $this->createTicket->create(
                $player,
                array_map('intval', $request->input('selected_positions')),
                (int) $request->input('stake_kobo'),
                $request->string('idempotency_key')->toString(),
                $request->string('tradition')->toString() ?: null,
                $request->string('leader_type')->toString() ?: null,
            );
        } catch (TicketEligibilityException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'selected_positions' => $ticket->predictionJson,
            'stake_kobo' => $ticket->stakeKobo,
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'purchased',
        ]);
    }

    /** GET /v1/heritage/tickets/{reference}/reveal */
    public function reveal(string $reference): JsonResponse
    {
        $player = $this->player();
        $ticket = $this->revealTicket->reveal($player, $reference);
        if ($ticket === null) {
            return response()->json(['message' => 'Ticket not found or not yet settled.'], 404);
        }

        $outcome = $ticket->outcome;
        $result = $outcome->resultJson;
        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'stake_kobo' => $ticket->stakeKobo,
            'tradition' => $result['tradition'],
            'leader_type' => $result['leader_type'],
            'board' => $result['board'],
            'positions' => $this->positionStatuses($result),
            'match_count' => $result['match_count'],
            'outcome_tier' => $result['outcome_tier'],
            'second_chance_stake_kobo' => $result['second_chance_stake_kobo'],
            'won' => $outcome->won,
            'gross_prize_kobo' => $outcome->grossPrizeKobo,
            'tax_withheld_kobo' => $outcome->taxWithheldKobo,
            'net_credit_kobo' => $outcome->netCreditKobo,
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_after_kobo' => $wallet->winningsBalanceKobo,
            'tax_rate_basis_points' => $outcome->taxRateBasisPoints,
            'tax_basis_label' => $outcome->taxBasisLabel,
            'ruleset_version' => $ticket->jurisdictionRulesetVersion,
            'prize_table_version' => $ticket->prizeTableVersion,
            'engine_version' => $ticket->engineVersion,
            'state_name' => self::STATE_NAMES[$ticket->stateCode] ?? $ticket->stateCode,
            'status' => 'settled',
        ]);
    }

    /**
     * REQ-HG-013 — all nine positions marked picked-and-winning, picked-and-not,
     * unpicked-but-winning, or unpicked-and-not. REQ-HG-014 — factual, no "so close"
     * framing is applied here; that discipline belongs to the frontend, but nothing
     * in this payload invites it either (plain booleans, no per-position copy).
     *
     * @param array<string, mixed> $result
     * @return list<array{position:int,number:int,picked:bool,winning:bool}>
     */
    private function positionStatuses(array $result): array
    {
        $winning = $result['winning_positions'];
        $selected = $result['selected_positions'];

        return array_map(fn (int $position) => [
            'position' => $position,
            'number' => $result['board'][$position],
            'picked' => in_array($position, $selected, true),
            'winning' => in_array($position, $winning, true),
        ], range(0, 8));
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
