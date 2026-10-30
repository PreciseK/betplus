<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\Doors\DoorsService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\DoorsRound;
use App\Models\FairnessSeed;
use App\Models\GameRegistry;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoorsController extends Controller
{
    public function __construct(private readonly DoorsService $doors, private readonly WalletService $wallet) {}

    public function show(Request $request): JsonResponse
    {
        $player = $this->player($request);
        $game = GameRegistry::where('gameCode', 'DOORS')->first();
        $config = null;
        try { $config = $this->doors->configuration(); } catch (TicketEligibilityException) {}
        $active = $this->doors->current($player);
        $latest = $active ?? DoorsRound::where('playerId', $player->id)->orderByDesc('id')->first();
        $rules = $config?->paramsJson['doors'] ?? null;
        return response()->json([
            'currency' => 'NGN', 'game_code' => 'DOORS',
            'available' => (bool) (config('doors.enabled') && $game?->status === 'ACTIVE' && $config),
            'min_stake_kobo' => $game?->minStakeKobo, 'max_stake_kobo' => $game?->maxStakeKobo,
            'economics_model' => 'BALANCED_HYBRID', 'prize_table_version' => $config?->version,
            'rules' => $rules ? array_diff_key($rules, ['certification_ref' => true]) : null,
            'wallet' => $this->walletShape($player), 'active_round' => $active ? $this->shape($active, $player) : null,
            'latest_round' => $latest ? $this->shape($latest, $player) : null,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['stake_kobo' => ['required', 'integer', 'min:1'], 'duration_seconds' => ['required', 'integer'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        try {
            $round = $this->doors->start($this->player($request), (int) $data['stake_kobo'], (int) $data['duration_seconds'], $data['idempotency_key']);
            return response()->json($this->shape($round, $this->player($request)));
        } catch (TicketEligibilityException $e) { return $this->error($e); }
    }

    public function read(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json($this->shape($this->doors->read($this->player($request), $reference), $this->player($request)));
        } catch (TicketEligibilityException $e) { return $this->error($e); }
    }

    public function open(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['door_index' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        try {
            return response()->json($this->shape($this->doors->read($this->player($request), $reference, (int) $data['door_index']), $this->player($request)));
        } catch (TicketEligibilityException $e) { return $this->error($e); }
    }

    /** @return array<string, mixed> */
    private function shape(DoorsRound $round, Player $player): array
    {
        $ticket = $round->ticket;
        $outcome = $ticket->outcome;
        $inPlay = $round->status === 'IN_PLAY';
        return [
            'reference' => $ticket->reference, 'status' => $inPlay ? 'in_play' : 'settled', 'currency' => 'NGN',
            'stake_kobo' => $ticket->stakeKobo, 'server_time' => now()->toIso8601String(),
            // Never report 0 while in play, or the client re-polls in a tight loop until settlement.
            'remaining_ms' => $inPlay ? max(1, (int) ceil(now()->diffInMilliseconds($round->expiresAt, false))) : 0,
            'expires_at' => $round->expiresAt->toIso8601String(), 'duration_seconds' => $round->configJson['duration_seconds'],
            'max_attempts' => $round->configJson['rules']['max_attempts'], 'board_size' => $round->configJson['rules']['board_size'],
            'opened' => $round->openedJson, 'commitment' => $round->commitment,
            // Revealed only after settlement so players can verify the commitment themselves.
            'seed_hex' => $inPlay ? null : FairnessSeed::find($ticket->rngSeedRef)?->seedHex,
            'prize_table_version' => $round->economicsVersion, 'engine_version' => $ticket->engineVersion,
            'gross_prize_kobo' => $outcome->grossPrizeKobo ?? 0, 'net_prize_kobo' => $outcome->netCreditKobo ?? 0,
            'tax_withheld_kobo' => $outcome->taxWithheldKobo ?? 0, 'tax_rate_basis_points' => $outcome->taxRateBasisPoints ?? 0,
            'tax_basis_label' => $outcome->taxBasisLabel ?? '', 'wallet' => $this->walletShape($player),
        ];
    }

    /** @return array{play_balance_kobo:int,bonus_balance_kobo:int,winnings_balance_kobo:int} */
    private function walletShape(Player $player): array
    {
        $wallet = $this->wallet->walletFor($player)->refresh();
        return ['play_balance_kobo' => $wallet->playBalanceKobo, 'bonus_balance_kobo' => $wallet->bonusBalanceKobo, 'winnings_balance_kobo' => $wallet->winningsBalanceKobo];
    }

    private function player(Request $request): Player { return $request->attributes->get('player'); }
    private function error(TicketEligibilityException $e): JsonResponse { return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422); }
}
