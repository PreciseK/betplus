<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Bootstrap\Config;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;
use BlackRed\Wallet\DepositService;
use BlackRed\Wallet\WithdrawalService;

/**
 * CallbackController — single unified webhook endpoint for ANM.
 *
 *   POST /api/callbacks/momo  (public)
 *
 * Why one endpoint for both deposits (CTM) and withdrawals (MTC)?
 *   - ANM sends the same body shape for both: { trans_ref, trans_status }
 *   - Configuring two URLs at ANM doubles the IP-allowlist work later
 *   - The exttrid is unique across both tables (it's our random 16-hex)
 *
 * Dispatch logic:
 *   1. Try DepositService.handleCallback — returns true if it found
 *      and handled (or already handled) a depositRequest with that exttrid.
 *   2. If deposit returned false (no row matched), try WithdrawalService.
 *   3. If neither matched, log and return 200 anyway (so ANM doesn't
 *      retry forever on a malformed/foreign callback).
 *
 * Authentication:
 *   - exttrid is unguessable (16 hex = 64 bits of entropy)
 *   - 10-min expiry window inside each service
 *   - Optional IP allowlist via ANM_CALLBACK_IPS env var
 */
final class CallbackController
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly WithdrawalService $withdrawals,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function momo(Request $request, array $params): Response
    {
        // Optional IP allowlist
        $allowlistRaw = trim($this->config->string('ANM_CALLBACK_IPS', ''));
        if ($allowlistRaw !== '') {
            $allowed = array_map('trim', explode(',', $allowlistRaw));
            if (!in_array($request->clientIp, $allowed, true)) {
                $this->logger->info('callback_ip_rejected', [
                    'callback_ip' => $request->clientIp,
                    'allowed'     => $allowed,
                ]);
                return Response::json(['ok' => false], 403);
            }
        } else {
            $this->logger->info('callback_no_ip_allowlist', [
                'callback_ip' => $request->clientIp,
            ]);
        }

        $body = $request->json();
        $exttrid     = isset($body['trans_ref'])    ? (string)$body['trans_ref']    : '';
        $transStatus = isset($body['trans_status']) ? (string)$body['trans_status'] : '';

        if ($exttrid === '' || $transStatus === '') {
            $this->logger->info('callback_bad_body', [
                'callback_ip' => $request->clientIp,
                'body_keys'   => array_keys($body),
            ]);
            return Response::json(['ok' => false]);
        }

        // Try deposit first. If it didn't match, try withdrawal. The two
        // services don't share exttrids (they're random), so order doesn't
        // affect correctness — only a tiny perf optimization. Deposits are
        // more frequent than withdrawals so they go first.
        $handled = $this->deposits->handleCallback($exttrid, $transStatus, $request->clientIp, $body);
        if (!$handled) {
            $handled = $this->withdrawals->handleCallback($exttrid, $transStatus, $request->clientIp, $body);
        }

        if (!$handled) {
            $this->logger->info('callback_no_match', [
                'callback_ip' => $request->clientIp,
                'exttrid_prefix' => substr($exttrid, 0, 4),
            ]);
        }

        return Response::json(['ok' => true]);
    }
}