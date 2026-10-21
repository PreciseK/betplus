<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\Engine\Heritage\HeritageEngine;
use App\Domain\Games\Engine\Heritage\HeritageTier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Direct HTTP API endpoints implementing the Engine Contract (PRD §6.2)
 * for the Heritage game in pure PHP.
 */
class EngineHeritageController extends Controller
{
    public function __construct(
        private readonly HeritageEngine $engine = new HeritageEngine(),
    ) {
    }

    /** POST /engine/v1/resolve */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => 'required|string',
            'game_code' => 'required|string|in:HERITAGE',
            'stake_kobo' => 'required|integer|min:1',
            'prize_table_version' => 'required|string',
            'prize_table' => 'required|array|min:1',
            'prize_table.*.name' => 'required|string',
            'prize_table.*.probability_basis_points' => 'required|integer|min:0|max:10000',
            'prize_table.*.multiplier_hundredths' => 'required|integer|min:0',
            'prize_table.*.outcome_type' => 'required|string|in:cash,draw_entry,none',
            'seed' => 'required|string|regex:/^[0-9a-fA-F]+$/',
            'player_input' => 'required|array',
            'player_input.selected_positions' => 'required|array|size:5',
            'player_input.selected_positions.*' => 'required|integer|min:0|max:8',
            'player_input.tradition' => 'required|string',
            'player_input.leader_type' => 'required|string|in:king,queen',
        ]);

        $tiers = array_map(fn (array $t) => new HeritageTier(
            name: $t['name'],
            probabilityBasisPoints: (int) $t['probability_basis_points'],
            multiplierHundredths: (int) $t['multiplier_hundredths'],
            outcomeType: $t['outcome_type'],
        ), $validated['prize_table']);

        try {
            $result = $this->engine->resolve(
                ticketId: $validated['ticket_id'],
                seedHex: $validated['seed'],
                stakeKobo: (int) $validated['stake_kobo'],
                tiers: $tiers,
                selectedPositions: $validated['player_input']['selected_positions'],
                tradition: $validated['player_input']['tradition'],
                leaderType: $validated['player_input']['leader_type'],
            );

            return response()->json($result->toContractArray($validated['ticket_id']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
    }

    /** POST /engine/v1/replay */
    public function replay(Request $request): JsonResponse
    {
        return $this->resolve($request);
    }

    /** POST /engine/v1/draw-pool */
    public function drawPool(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seed' => 'required|string|regex:/^[0-9a-fA-F]+$/',
        ]);

        try {
            $winningPositions = $this->engine->drawPoolOutcome($validated['seed']);

            return response()->json([
                'winning_positions' => $winningPositions,
                'engine_version' => HeritageEngine::VERSION,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
    }

    /** GET /engine/v1/describe */
    public function describe(): JsonResponse
    {
        return response()->json($this->engine->describe());
    }
}
