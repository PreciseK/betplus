<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Wallet\TransactionsService;

/**
 * GET /api/transactions
 *
 *   ?type=deposit | withdraw | all   (default: all)
 *   ?limit=15                        (default: 15, max 50)
 *   ?cursor={opaque}                 (default: null = first page)
 *
 * Auth required. Player ID comes from AuthMiddleware.
 *
 * Response:
 *   {
 *     "items": [ ... ],
 *     "nextCursor": "..." | null,
 *     "hasMore": true | false
 *   }
 */
final class TransactionsController
{
    public function __construct(
        private readonly TransactionsService $service,
    ) {
    }

    public function index(Request $req): Response
    {
        $playerId = (int)$req->getAttribute('player_id');

        $type   = $req->query['type']   ?? 'all';
        $limit  = isset($req->query['limit'])  ? (int)$req->query['limit']  : TransactionsService::DEFAULT_LIMIT;
        $cursor = $req->query['cursor'] ?? null;

        // Normalize cursor: empty string → null
        if ($cursor === '') {
            $cursor = null;
        }

        $page = $this->service->listForPlayer($playerId, $type, $limit, $cursor);

        return Response::json($page, 200);
    }
}