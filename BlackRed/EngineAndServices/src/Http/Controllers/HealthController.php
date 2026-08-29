<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Bootstrap\Config;
use BlackRed\Database\Connection;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use Throwable;

/**
 * Health check endpoint.
 *
 * GET /api/health
 *
 * Returns:
 *   200 { ok: true,  app: "BlackRed Raffle", env: "production", db: true,  time: "..." }
 *   503 { ok: false, app: "BlackRed Raffle", env: "production", db: false, time: "...", error: "..." }
 *
 * Use cases:
 *  - Uptime monitoring (every 60s from an external service)
 *  - Deployment verification ("is the new version up and connected?")
 *  - Quick smoke test after config changes
 *
 * IMPORTANT: this endpoint does NOT require authentication. We're careful to
 * leak nothing sensitive — no version numbers, no DB hostnames, no schema
 * names. Only a yes/no on each subsystem.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {
    }

    public function check(Request $request, array $params): Response
    {
        $dbOk = false;
        $dbError = null;

        try {
            $value = $this->db->fetchValue('SELECT 1');
            $dbOk = ((int)$value === 1);
        } catch (Throwable $e) {
            $dbError = 'connection_failed';
        }

        $body = [
            'ok' => $dbOk,
            'app' => $this->config->string('APP_NAME', 'BlackRed Raffle'),
            'env' => $this->config->string('APP_ENV', 'unknown'),
            'db' => $dbOk,
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($dbError !== null) {
            $body['error'] = $dbError;
        }

        return Response::json($body, $dbOk ? 200 : 503);
    }
}
