<?php

declare(strict_types=1);

/**
 * Engine purity gate — REQ-GEC-001, REQ-GEC-002, REQ-ARCH-002, REQ-QA-004.
 *
 * A game engine is a pure function of (seed, ticket_id, player_input). It holds no state, reads
 * and writes no database, calls nothing over the network, and touches no file. Identical inputs
 * must produce byte-identical output on every host, restart and deployment of the same version.
 *
 * This gate exists because of a specific defect in the system being replaced: its engine opened
 * a database transaction, read mutable shared state, wrote ledger entries and moved money — all
 * inside the function that decided whether a player won. That engine cannot be certified,
 * cannot publish true odds, and cannot be replayed for an audit.
 *
 * Two rules:
 *   1. No engine may reach a database, the network or the filesystem.
 *   2. Nothing outside the Wallet Service may write to a ledger table (REQ-ARCH-002).
 *
 * The second rule is also enforced by database grants in production. Enforcing it here means a
 * violation is caught at review time rather than at deploy time.
 *
 * Usage: php tools/gates/engine-purity.php
 * Exit:  0 = clean · 1 = violation found
 */

require __DIR__ . '/_walk.php';

const ROOT = __DIR__ . '/../..';
// Resolved once — see prohibited-randomness.php for why this matters on Windows.
$GLOBALS['__ROOT_REAL'] = realpath(ROOT);

const ENGINE_PATHS = ['apps/engine-blackred', 'apps/engine-heritage'];

/** Impurity in any engine. Substring match against stripped code. */
const IMPURE_PHP = [
    'PDO' => 'database access',
    'mysqli' => 'database access',
    'curl_init' => 'network call',
    'file_get_contents' => 'filesystem or network access',
    'file_put_contents' => 'filesystem write',
    'fopen' => 'filesystem access',
    'fwrite' => 'filesystem write',
    'unlink' => 'filesystem write',
    'stream_socket_client' => 'network call',
    'fsockopen' => 'network call',
    'DB::' => 'database access',
    'Eloquent' => 'database access',
    'time()' => 'non-determinism — an engine must not read the clock',
    'date(' => 'non-determinism — an engine must not read the clock',
    'microtime' => 'non-determinism — an engine must not read the clock',
    'getenv' => 'ambient state — inputs arrive as arguments',
    '$_ENV' => 'ambient state — inputs arrive as arguments',
    '$_SESSION' => 'state — an engine is stateless',
];

const IMPURE_PY = [
    'import sqlite3' => 'database access',
    'import psycopg' => 'database access',
    'import pymysql' => 'database access',
    'import requests' => 'network call',
    'import httpx' => 'network call',
    'urllib.request' => 'network call',
    'open(' => 'filesystem access',
    'datetime.now' => 'non-determinism — an engine must not read the clock',
    'time.time' => 'non-determinism — an engine must not read the clock',
    'os.environ' => 'ambient state — inputs arrive as arguments',
];

/** Ledger tables. Only the Wallet Service may write these. */
const LEDGER_TABLES = ['ledgerEntry', 'walletTransaction', 'wallet', 'account'];

const WALLET_SERVICE_PATH = 'apps/platform/app/Domain/Wallet';

$violations = [];

function rel(string $path): string
{
    return ltrim(str_replace('\\', '/', substr($path, strlen($GLOBALS['__ROOT_REAL']))), '/');
}

function skip(string $path): bool
{
    $p = str_replace('\\', '/', $path);
    // Tests exercise impurity deliberately (fixtures, mocks); migrations are schema, not logic.
    if (str_contains($p, '/tests/') || str_contains($p, '/database/migrations/')) {
        return true;
    }
    return str_starts_with(rel($path), 'BlackRed/');
}

function inEngine(string $path): bool
{
    $p = str_replace('\\', '/', $path);
    foreach (ENGINE_PATHS as $e) {
        if (str_contains($p, $e)) {
            return true;
        }
    }
    return false;
}

/** Remove comments and string literals so prose never trips the gate. */
function bareCode(string $code, string $ext): string
{
    if ($ext === 'php') {
        $out = '';
        foreach (@token_get_all($code) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    // Python: drop # comments and obvious string literals.
    $out = preg_replace('/#.*$/m', '', $code);
    $out = preg_replace('/"""[\s\S]*?"""/', '', (string) $out);
    return (string) preg_replace("/'''[\s\S]*?'''/", '', (string) $out);
}

foreach (gateWalk(ROOT) as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || skip($path)) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'py'], true)) {
        continue;
    }

    $r = rel($path);
    $code = bareCode(file_get_contents($path), $ext);

    // Rule 1 — engine purity.
    if (inEngine($path)) {
        $rules = $ext === 'php' ? IMPURE_PHP : IMPURE_PY;
        foreach ($rules as $needle => $why) {
            if (str_contains($code, $needle)) {
                $violations[] = sprintf('%s  uses "%s" — %s (REQ-GEC-002)', $r, $needle, $why);
            }
        }
    }

    // Rule 2 — only the Wallet Service writes ledger tables.
    if ($ext === 'php' && !str_contains(str_replace('\\', '/', $path), WALLET_SERVICE_PATH)) {
        foreach (LEDGER_TABLES as $t) {
            if (preg_match('/\b(insert|update|delete)\b[^;]{0,120}\b' . preg_quote($t, '/') . '\b/i', $code)) {
                $violations[] = sprintf(
                    '%s  appears to write "%s" outside the Wallet Service (REQ-ARCH-001, REQ-ARCH-002)',
                    $r,
                    $t
                );
            }
        }
    }
}

if ($violations === []) {
    echo "engine-purity: OK — engines are pure, and only the Wallet Service writes the ledger.\n";
    exit(0);
}

fwrite(STDERR, 'engine-purity: ' . count($violations) . " violation(s)\n\n");
foreach (array_unique($violations) as $v) {
    fwrite(STDERR, "  {$v}\n");
}
fwrite(
    STDERR,
    "\n  An engine takes a seed and returns an outcome. If it needs anything else, the platform\n" .
    "  passes it in as an argument. If money needs to move, the platform moves it.\n"
);
exit(1);
