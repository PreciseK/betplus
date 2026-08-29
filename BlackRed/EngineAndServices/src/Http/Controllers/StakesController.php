<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Wallet\StakesService;

/**
 * GET /api/stakes?filter=today|week|month
 *
 * Returns the authenticated player's game-round history for the chosen
 * window, with summary stats (played / won / lost).
 *
 * Auth required. Player ID comes from AuthMiddleware.
 *
 * Response:
 *   {
 *     "filter": "today",
 *     "stats": { "played": 5, "won": 2, "lost": 3,
 *                "totalStakedPesewas": 4000, "totalWonPesewas": 2000 },
 *     "items": [ {
 *         "refNumber": "STK-abc123...",
 *         "gameType": 2, "multiplier": 10,
 *         "colorPicks": ["red","black"],
 *         "stakePesewas": 500,
 *         "payoutPesewas": 5000,
 *         "outcome": "win",
 *         "drawnCards": [{"rank":"7","suit":"♥","color":"red"}, ...],
 *         "createdAt": "2026-04-30T14:32:11Z"
 *     }, ... ]
 *   }
 */
final class StakesController
{
    public function __construct(
        private readonly StakesService $service,
    ) {
    }

    public function index(Request $req): Response
    {
        $playerId = (int)$req->getAttribute('player_id');
        $filter   = (string)($req->query['filter'] ?? 'today');

        $page = $this->service->listForPlayer($playerId, $filter);
        return Response::json($page, 200);
    }
}