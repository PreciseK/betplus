<?php

declare(strict_types=1);

namespace BlackRed\Database;

use BlackRed\Bootstrap\Config;
use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO database connection wrapper.
 *
 * Single shared connection per request (PHP-FPM worker recycles it on next
 * request anyway). Lazy-connected on first use.
 *
 * Provides:
 *  - Safe parameter binding via prepared statements (always)
 *  - Transaction helper with retry on deadlock
 *  - Convenience methods for common query patterns
 *
 * Hardening:
 *  - PDO::ATTR_EMULATE_PREPARES = false (real prepared statements)
 *  - PDO::ATTR_ERRMODE = EXCEPTION (every error becomes a PDOException)
 *  - PDO::ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC (no numeric duplicates)
 */
final class Connection
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config->string('DB_HOST');
        $port = $this->config->int('DB_PORT', 3306);
        $name = $this->config->string('DB_NAME');
        $user = $this->config->string('DB_USER');
        $pass = $this->config->string('DB_PASS', '');
        $charset = $this->config->string('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // Persistent connections are tempting on shared hosting but cause
            // session-state bugs (e.g. transactions left open by a prior request).
            // We do NOT enable them.
            PDO::ATTR_PERSISTENT         => false,
            // Set strict SQL mode + UTC timezone on every connection.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Don't leak credentials or DSN in the exception message.
            throw new RuntimeException(
                'Database connection failed. Check DB_* environment variables.',
                0,
                $e
            );
        }

        return $this->pdo;
    }

    /**
     * Execute a query with bound parameters. Returns the prepared statement.
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row or null.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single scalar value (first column of first row), or null.
     *
     * @param array<string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->execute($sql, $params);
        $val = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * Insert a row and return the autoincrement ID.
     *
     * @param array<string, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * Execute work inside a transaction with automatic deadlock retry.
     *
     * The closure receives the connection and may run any queries. If it throws,
     * the transaction is rolled back. If the throw is a deadlock (MySQL error
     * 1213) and we have retries left, we retry up to $maxRetries times.
     *
     * Nested calls reuse the outer transaction (savepoints not used — we don't
     * need them for our use cases).
     *
     * @template T
     * @param Closure(self): T $work
     * @return T
     */
    public function transactional(Closure $work, int $maxRetries = 3): mixed
    {
        // Re-entrance: if we're already in a transaction, just run the work.
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $work($this);
            } finally {
                $this->transactionDepth--;
            }
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->pdo()->beginTransaction();
            $this->transactionDepth = 1;
            try {
                $result = $work($this);
                $this->pdo()->commit();
                $this->transactionDepth = 0;
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
                $this->transactionDepth = 0;

                if ($this->isDeadlock($e) && $attempt <= $maxRetries) {
                    // Backoff: 10ms, 30ms, 90ms
                    $sleepUs = (int)(10000 * (3 ** ($attempt - 1)));
                    usleep($sleepUs);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * MySQL deadlock = SQLSTATE 40001, error code 1213. Lock wait timeout = 1205.
     * Both are retryable.
     */
    private function isDeadlock(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? null;
        if (!is_array($info)) {
            return false;
        }
        $code = (int)($info[1] ?? 0);
        return $code === 1213 || $code === 1205;
    }
}
