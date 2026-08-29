<?php
/**
 * DB access.
 *
 * Two public functions:
 *   db()           — returns the PDO instance (lazy-connect, cached for request)
 *   dbTxn($fn)     — runs $fn inside a transaction; rolls back on throw
 *
 * Why no class: this is the entirety of our DB layer. A single PDO instance
 * per request is sufficient — no pooling, no migrations runner, no fluent
 * query builder.
 *
 * The web app uses BlackRed\Database\Connection. We do NOT share that file
 * because we're a separate slim app with no Composer autoload. The behaviour
 * is intentionally similar (same PDO attrs, same charset, same SQL mode) so
 * that queries written against either DB layer behave identically.
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    global $USSD_CONFIG;
    $c = $USSD_CONFIG['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['name'], $c['charset']
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // No persistent connections (see web app Connection.php for rationale).
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
        ]);
    } catch (PDOException $e) {
        // Don't leak DSN or creds in error message
        throw new RuntimeException('Database connection failed.', 0, $e);
    }

    return $pdo;
}

/**
 * Run a closure inside a DB transaction. The closure receives the PDO.
 * If it throws, the transaction is rolled back and the exception re-thrown.
 *
 * Nesting: if a transaction is already active (i.e. dbTxn was called from
 * within another dbTxn), we run the callback inline without opening a new
 * transaction. The outer txn's commit/rollback governs both. This is the
 * standard pattern for "I might be called from inside or outside a txn".
 *
 * No deadlock retry for v1 — USSD turns are short enough that contention is
 * rare. We can add retry in hardening pass.
 */
function dbTxn(callable $fn): mixed
{
    $pdo = db();

    // Already inside a transaction — just run inline. Outer caller owns
    // commit/rollback.
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }

    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
