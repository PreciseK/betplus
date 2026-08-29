<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Bootstrap\Config;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;
use BlackRed\Support\Money;
use BlackRed\Validation\Validator;
use BlackRed\Wallet\WithdrawalService;

/**
 * WithdrawalController — Phase 4 withdrawal endpoints.
 *
 *   POST /api/withdrawals               (auth) — start a withdrawal
 *   GET  /api/withdrawals/{id}          (auth) — read status
 *   POST /api/withdrawals/{id}/verify   (auth) — TSC fallback (Type B only)
 *
 * The webhook callback for Type B is handled by CallbackController
 * (single unified endpoint at /api/callbacks/momo for both deposits
 * and withdrawals).
 */
final class WithdrawalController
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /api/withdrawals
     * Body: { "destination": "PLAY" | "MOMO", "amount": "5.00" }
     */
    public function create(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }

        $v = new Validator($request->json());
        $destination = $v->requireString('destination', 1, 10);
        if (!in_array($destination, ['PLAY', 'MOMO'], true)) {
            throw new HttpException(422, 'invalid_destination', 'Destination must be PLAY or MOMO.');
        }
        $amountStr = $v->requireString('amount', 1, 20);
        $amountPesewas = Money::parseGhsToPesewas($amountStr);
        if ($amountPesewas === null) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be a number with up to 2 decimal places, e.g. 5.00');
        }

        $row = $this->withdrawals->initiate($playerId, $destination, $amountPesewas, $request->clientIp);

        return Response::json($this->serialize($row), 201);
    }

    /**
     * GET /api/withdrawals/{id}
     */
    public function get(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(400, 'invalid_id', 'Invalid withdrawal id.');
        }

        $row = $this->withdrawals->getById($id, $playerId);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Withdrawal not found.');
        }

        return Response::json($this->serialize($row));
    }

    /**
     * POST /api/withdrawals/{id}/verify
     * For Type B (MOMO): asks ANM via TSC if our local row is still pending.
     * For Type A (PLAY): no-op, just returns the row.
     */
    public function verify(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(400, 'invalid_id', 'Invalid withdrawal id.');
        }

        $row = $this->withdrawals->verifyWithAnm($id, $playerId);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Withdrawal not found.');
        }

        return Response::json($this->serialize($row));
    }

    /**
     * Shape a withdrawalRequest row for the API client. Strips internal
     * audit fields (callback IP, fee, raw payloads) the player doesn't need.
     * Notably feePesewas is NOT exposed — fees are absorbed silently per spec.
     */
    private function serialize(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        $amount = (int)$row['amountPesewas'];
        return [
            'id'              => (int)$row['id'],
            'reference'       => (string)$row['refNumber'],
            'destination'     => (string)$row['destination'],
            'amountPesewas'   => $amount,
            'amountFormatted' => Money::pesewasToString($amount),
            'currency'        => (string)$row['currency'],
            'status'          => (string)$row['status'],
            'failureReason'   => $row['failureReason'] !== null ? (string)$row['failureReason'] : null,
            'paymentProvider' => (string)$row['paymentProvider'],
            'phone'           => (string)$row['msisdn'],
            'initiatedAt'     => (string)$row['initiatedAt'],
            'completedAt'     => $row['completedAt'] !== null ? (string)$row['completedAt'] : null,
            'expiresAt'       => $row['expiresAt'] !== null ? (string)$row['expiresAt'] : null,
        ];
    }
}