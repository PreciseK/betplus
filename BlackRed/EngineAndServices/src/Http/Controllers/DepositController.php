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
use BlackRed\Wallet\DepositService;

/**
 * DepositController — Phase 3 deposit endpoints.
 *
 *   POST /api/deposits          (auth)   — initiate a deposit
 *   GET  /api/deposits/{id}     (auth)   — poll status
 *   POST /api/callbacks/momo    (public) — ANM webhook
 *
 * The callback endpoint is public because ANM can't carry a session
 * cookie; we authenticate the callback by:
 *   1. The exttrid being unguessable (32 hex chars)
 *   2. The expiresAt time-bounding to ~10 minutes from initiate
 *   3. (Future) ANM IP allowlist via ANM_CALLBACK_IPS env var
 */
final class DepositController
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * POST /api/deposits
     * Body: { "amount": "50.00" }   (GHS string with 2 decimal places)
     */
    public function create(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }

        $v = new Validator($request->json());
        $amountStr = $v->requireString('amount', 1, 20);
        $amountPesewas = Money::parseGhsToPesewas($amountStr);
        if ($amountPesewas === null) {
            throw new HttpException(422, 'invalid_amount', 'Amount must be a number with up to 2 decimal places, e.g. 50.00');
        }

        $deposit = $this->deposits->initiate($playerId, $amountPesewas, $request->clientIp);

        return Response::json($this->serialize($deposit), 201);
    }

    /**
     * GET /api/deposits/{id}
     */
    public function get(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }
        $depositId = (int)($params['id'] ?? 0);
        if ($depositId <= 0) {
            throw new HttpException(400, 'invalid_id', 'Invalid deposit id.');
        }

        $deposit = $this->deposits->getById($depositId, $playerId);
        if ($deposit === null) {
            throw new HttpException(404, 'not_found', 'Deposit not found.');
        }

        return Response::json($this->serialize($deposit));
    }

    /**
     * POST /api/deposits/{id}/verify
     *
     * User-driven status check, invoked when the player taps "I've made
     * payment". We actively query ANM's TSC endpoint if our local row is
     * still pending — this recovers from lost webhook callbacks.
     *
     * Always returns the latest deposit shape; caller decides what to do
     * based on status (succeeded/failed/pending).
     */
    public function verify(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }
        $depositId = (int)($params['id'] ?? 0);
        if ($depositId <= 0) {
            throw new HttpException(400, 'invalid_id', 'Invalid deposit id.');
        }

        $deposit = $this->deposits->verifyWithAnm($depositId, $playerId);
        if ($deposit === null) {
            throw new HttpException(404, 'not_found', 'Deposit not found.');
        }

        return Response::json($this->serialize($deposit));
    }

    /**
     * POST /api/callbacks/momo
     *
     * Public endpoint — ANM POSTs JSON like { trans_ref, trans_status }.
     * We always return 200 to ANM regardless of internal outcome so they
     * don't keep retrying. Legitimate failures (status != 000) are recorded
     * in the depositRequest row, not bounced back as HTTP errors.
     */
    public function callback(Request $request, array $params): Response
    {
        // Optional IP allowlist — only enforced if env var set
        $allowlistRaw = trim($this->config->string('ANM_CALLBACK_IPS', ''));
        if ($allowlistRaw !== '') {
            $allowed = array_map('trim', explode(',', $allowlistRaw));
            if (!in_array($request->clientIp, $allowed, true)) {
                $this->logger->info('deposit_callback_ip_rejected', [
                    'callback_ip' => $request->clientIp,
                    'allowed'     => $allowed,
                ]);
                // 403 here so an attacker can't easily distinguish "wrong IP"
                // from "wrong exttrid" — but we LOG the attempt so we can
                // notice probes.
                return Response::json(['ok' => false], 403);
            }
        } else {
            $this->logger->info('deposit_callback_no_ip_allowlist', [
                'callback_ip' => $request->clientIp,
            ]);
        }

        $body = $request->json();
        $exttrid    = isset($body['trans_ref']) ? (string)$body['trans_ref'] : '';
        $transStatus = isset($body['trans_status']) ? (string)$body['trans_status'] : '';

        if ($exttrid === '' || $transStatus === '') {
            $this->logger->info('deposit_callback_bad_body', [
                'callback_ip' => $request->clientIp,
                'body_keys'   => array_keys($body),
            ]);
            // Still 200 to ANM so they don't retry a malformed payload forever
            return Response::json(['ok' => false]);
        }

        $this->deposits->handleCallback($exttrid, $transStatus, $request->clientIp, $body);

        return Response::json(['ok' => true]);
    }

    /**
     * Shape a depositRequest row for the API client. Hide the full ledger
     * detail; the player just needs status + amount + ref.
     */
    private function serialize(?array $row): array
    {
        if ($row === null) return [];

        $amountPesewas = (int)$row['amountPesewas'];
        return [
            'id'              => (int)$row['id'],
            'reference'       => (string)$row['refNumber'],
            'amountPesewas'   => $amountPesewas,
            'amountFormatted' => Money::pesewasToString($amountPesewas),
            'currency'        => 'GHS',
            'status'          => (string)$row['status'],
            'failureReason'   => $row['failureReason'] ?? null,
            'paymentProvider' => (string)$row['paymentProvider'],
            'phone'           => (string)$row['msisdn'],
            'initiatedAt'     => (string)$row['initiatedAt'],
            'confirmedAt'     => $row['confirmedAt'] ?? null,
            'expiresAt'       => $row['expiresAt'] ?? null,
        ];
    }
}