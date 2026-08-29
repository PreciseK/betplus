<?php

declare(strict_types=1);

/**
 * Schema drift gate — Story 1.3, REQ-DATA-001.
 *
 * Fails when the live schema differs from what a fresh `migrate` produces.
 *
 * This gate exists because of a specific defect in the system this project replaces: five
 * migrations (003–007) were never committed, so the only authoritative copy of the schema was
 * the running production database. No staging environment could be built and no automated test
 * could run. This check makes that state impossible to re-enter — the moment someone applies a
 * change by hand, the build goes red.
 *
 * Usage:
 *   php tools/gates/schema-drift.php                 # compare default connection to fresh migrate
 *   php tools/gates/schema-drift.php --print         # print the fingerprint and exit 0
 *
 * Exit codes: 0 = no drift · 1 = drift detected · 2 = could not run the comparison.
 */

const PLATFORM = __DIR__ . '/../../apps/platform';

function fail(string $msg, int $code = 2): never
{
    fwrite(STDERR, "schema-drift: {$msg}\n");
    exit($code);
}

/**
 * Environment is passed through proc_open rather than as a `VAR=x cmd` prefix, which is POSIX
 * shell syntax and fails on Windows. This gate has to run on both a developer's machine and CI.
 */
function run(string $cmd, ?string $cwd = null, array $env = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $merged = array_merge(getenv(), $env);
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $merged);
    if (!is_resource($proc)) {
        fail("could not execute: {$cmd}");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $p) {
        fclose($p);
    }
    return [proc_close($proc), $out, $err];
}

/**
 * A stable fingerprint of a schema: every table, column, type, nullability and index,
 * ordered deterministically so two equivalent schemas hash identically.
 */
function fingerprint(PDO $db, string $driver): string
{
    $lines = [];

    if ($driver === 'sqlite') {
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table'
             AND name NOT LIKE 'sqlite_%' AND name <> 'migrations' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $t) {
            foreach ($db->query("PRAGMA table_info(" . $db->quote($t) . ")") as $c) {
                $lines[] = sprintf(
                    '%s.%s %s null=%d',
                    $t,
                    $c['name'],
                    strtolower((string) $c['type']),
                    $c['notnull'] ? 0 : 1
                );
            }
            foreach ($db->query("PRAGMA index_list(" . $db->quote($t) . ")") as $i) {
                $cols = [];
                foreach ($db->query("PRAGMA index_info(" . $db->quote($i['name']) . ")") as $ic) {
                    $cols[] = $ic['name'];
                }
                $lines[] = sprintf('%s IDX %s unique=%d (%s)', $t, $i['name'], $i['unique'], implode(',', $cols));
            }
        }
    } else {
        $schema = $db->query('SELECT DATABASE()')->fetchColumn();

        $sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> 'migrations'
                ORDER BY TABLE_NAME, COLUMN_NAME";
        $st = $db->prepare($sql);
        $st->execute([$schema]);
        foreach ($st as $r) {
            $lines[] = sprintf(
                '%s.%s %s null=%s default=%s',
                $r['TABLE_NAME'],
                $r['COLUMN_NAME'],
                strtolower((string) $r['COLUMN_TYPE']),
                $r['IS_NULLABLE'] === 'YES' ? 1 : 0,
                $r['COLUMN_DEFAULT'] ?? 'NULL'
            );
        }

        $sql = "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE,
                       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLS
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> 'migrations'
                GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
                ORDER BY TABLE_NAME, INDEX_NAME";
        $st = $db->prepare($sql);
        $st->execute([$schema]);
        foreach ($st as $r) {
            $lines[] = sprintf(
                '%s IDX %s unique=%d (%s)',
                $r['TABLE_NAME'],
                $r['INDEX_NAME'],
                $r['NON_UNIQUE'] ? 0 : 1,
                $r['COLS']
            );
        }
    }

    sort($lines, SORT_STRING);

    return hash('sha256', implode("\n", $lines));
}

// --- Build a reference schema from migrations alone, in a throwaway SQLite database. ---

$tmp = sys_get_temp_dir() . '/betplus-drift-' . bin2hex(random_bytes(6)) . '.sqlite';
touch($tmp);

[$code, $out, $err] = run(
    'php artisan migrate --force --no-interaction',
    PLATFORM,
    ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $tmp]
);

if ($code !== 0) {
    @unlink($tmp);
    fail("a fresh migrate failed, so the migrations themselves are broken:\n{$out}{$err}");
}

$reference = fingerprint(new PDO('sqlite:' . $tmp), 'sqlite');
@unlink($tmp);

if (in_array('--print', $argv, true)) {
    echo "reference fingerprint: {$reference}\n";
    exit(0);
}

// --- Compare against the live schema, when one is reachable. ---

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: '';
$user = getenv('DB_USER') ?: getenv('DB_USERNAME') ?: '';
$pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';

if ($name === '') {
    fwrite(
        STDERR,
        "schema-drift: no live database configured (set DB_NAME), so only the fresh-migrate\n" .
        "              build was verified. Migrations apply cleanly.\n" .
        "              Reference fingerprint: {$reference}\n"
    );
    exit(0);
}

try {
    $live = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail('could not reach the live database: ' . $e->getMessage());
}

$actual = fingerprint($live, 'mysql');

if ($actual === $reference) {
    echo "schema-drift: OK — live schema matches a fresh migrate.\n";
    exit(0);
}

fwrite(
    STDERR,
    "schema-drift: DRIFT DETECTED\n" .
    "  fresh migrate: {$reference}\n" .
    "  live schema:   {$actual}\n\n" .
    "  The live schema no longer matches what the migrations produce. Either a change was\n" .
    "  applied by hand, or a migration is missing from source control. Do not resolve this by\n" .
    "  editing the database — write the migration.\n"
);
exit(1);
