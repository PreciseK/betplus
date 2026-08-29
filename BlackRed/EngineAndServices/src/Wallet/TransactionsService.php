<?php

declare(strict_types=1);

namespace BlackRed\Wallet;

use BlackRed\Database\Connection;
use BlackRed\Logging\Logger;
use BlackRed\Support\Money;

/**
 * Unified transaction history feed.
 *
 * Merges depositRequest + withdrawalRequest rows into a single chronological
 * stream for the Transactions page. Returns simplified status (succeeded,
 * pending, failed) regardless of internal nuance.
 *
 * Design notes:
 *
 *   - Cursor pagination by initiatedAt + id (composite tiebreaker for rows
 *     with identical timestamps). Cursor is opaque "ISO8601|id" strings.
 *
 *   - Deposit + withdrawal rows live in different tables. We UNION them
 *     in SQL, then sort+slice. With our row counts (single-digit thousands
 *     per active player), this is fine. If it becomes a hot path at scale
 *     we'll move to a denormalized transaction view.
 *
 *   - Status normalization is deliberate. "expired" (deposit window passed)
 *     and "timeout" (withdrawal callback never arrived) both surface as
 *     "failed" because user-side they amount to the same thing — the
 *     transaction did not complete. Internal team can still see the original
 *     status in the underlying table.
 *
 *   - "Move to Play" (Type A withdrawal, destination=PLAY) shows under
 *     withdrawals tab as a distinct subtype. The frontend differentiates
 *     by row.subtype = 'PLAY' vs 'MOMO'.
 */
final class TransactionsService
{
    public const TYPE_DEPOSIT  = 'deposit';
    public const TYPE_WITHDRAW = 'withdraw';
    public const TYPE_ALL      = 'all';

    /** Default page size — 15 per spec */
    public const DEFAULT_LIMIT = 15;
    public const MAX_LIMIT     = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Page through a player's transactions.
     *
     * @param int      $playerId
     * @param string   $type      'deposit' | 'withdraw' | 'all'
     * @param int      $limit     1..50
     * @param ?string  $cursor    Opaque cursor: "ISO8601|id". Null = first page.
     *
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   nextCursor: ?string,
     *   hasMore: bool,
     * }
     */
    public function listForPlayer(int $playerId, string $type, int $limit, ?string $cursor): array
    {
        $type  = in_array($type, [self::TYPE_DEPOSIT, self::TYPE_WITHDRAW, self::TYPE_ALL], true) ? $type : self::TYPE_ALL;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Decode cursor. Null cursor = first page.
        // We over-fetch by 1 to determine if there are more pages.
        $fetchN = $limit + 1;
        [$cursorTs, $cursorId] = $this->decodeCursor($cursor);

        $unionParts = [];
        $params = [];

        if ($type === self::TYPE_DEPOSIT || $type === self::TYPE_ALL) {
            $unionParts[] = "
                SELECT
                    'deposit' AS feedType,
                    CAST(NULL AS CHAR(10)) AS feedSubtype,
                    id, refNumber, playerId, msisdn, paymentProvider,
                    amountPesewas,
                    CAST(status AS CHAR(20)) AS status,
                    CAST(NULL AS CHAR(500)) AS failureReason,
                    initiatedAt, confirmedAt AS completedAt
                FROM depositRequest
                WHERE playerId = :pid_dep
            ";
            $params[':pid_dep'] = $playerId;
        }

        if ($type === self::TYPE_WITHDRAW || $type === self::TYPE_ALL) {
            $unionParts[] = "
                SELECT
                    'withdraw' AS feedType,
                    CAST(destination AS CHAR(10)) AS feedSubtype,
                    id, refNumber, playerId, msisdn, paymentProvider,
                    amountPesewas,
                    CAST(status AS CHAR(20)) AS status,
                    CAST(failureReason AS CHAR(500)) AS failureReason,
                    initiatedAt, completedAt
                FROM withdrawalRequest
                WHERE playerId = :pid_wd
            ";
            $params[':pid_wd'] = $playerId;
        }

        $sql = '(' . implode(') UNION ALL (', $unionParts) . ')';
        $sql = "SELECT * FROM ({$sql}) AS feed";

        // Cursor predicate: rows strictly older than (cursorTs, cursorId)
        if ($cursorTs !== null) {
            $sql .= " WHERE (initiatedAt < :cts1 OR (initiatedAt = :cts2 AND id < :cid))";
            $params[':cts1'] = $cursorTs;
            $params[':cts2'] = $cursorTs;
            $params[':cid']  = $cursorId;
        }

        $sql .= " ORDER BY initiatedAt DESC, id DESC LIMIT {$fetchN}";

        $rows = $this->db->fetchAll($sql, $params);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(fn(array $r) => $this->shapeRow($r), $rows);

        $nextCursor = null;
        if ($hasMore && !empty($rows)) {
            $last = end($rows);
            $nextCursor = $this->encodeCursor((string)$last['initiatedAt'], (int)$last['id']);
        }

        return [
            'items' => $items,
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Convert a raw feed row into the shape sent to the frontend.
     * Sensitive internal columns (failureReason on succeeded rows, etc.)
     * are filtered out here.
     */
    private function shapeRow(array $r): array
    {
        $type    = (string)$r['feedType'];
        $subtype = $r['feedSubtype'] !== null ? (string)$r['feedSubtype'] : null;
        $rawStatus = (string)$r['status'];
        $status  = $this->normalizeStatus($rawStatus);

        $amountPesewas = (int)$r['amountPesewas'];
        $reference     = (string)$r['refNumber'];
        $msisdn        = (string)$r['msisdn'];
        $provider      = (string)$r['paymentProvider'];
        $initiatedAt   = (string)$r['initiatedAt'];
        $completedAt   = $r['completedAt'] !== null ? (string)$r['completedAt'] : null;
        $failureReason = ($status === 'failed' && $r['failureReason'] !== null)
            ? (string)$r['failureReason']
            : null;

        return [
            'id'              => (int)$r['id'],
            'type'            => $type,
            'subtype'         => $subtype,                               // 'MOMO' | 'PLAY' | null
            'status'          => $status,                                // succeeded | pending | failed
            'amountPesewas'   => $amountPesewas,
            'amountFormatted' => Money::pesewasToString($amountPesewas),
            'currency'        => 'GHS',
            'reference'       => $reference,
            'phone'           => $msisdn,                                  // canonical 233...; FE formats
            'paymentProvider' => $provider,                              // MTN | ATL | TEL
            'failureReason'   => $failureReason,
            'initiatedAt'     => $initiatedAt,
            'completedAt'     => $completedAt,
        ];
    }

    /**
     * Map internal DB status enum values to the 3 statuses surfaced to users.
     *
     * Buckets:
     *   succeeded → "Successful"   (visible)
     *   pending   → "Pending"      (visible) — covers initiated, pending, reconciling
     *   failed    → "Failed"       (visible) — covers failed, timeout, expired
     *
     * Anything we don't recognise defaults to 'failed' as the safest bucket
     * (we'd rather show "didn't complete" than incorrectly imply the money is
     * in flight).
     */
    private function normalizeStatus(string $raw): string
    {
        return match ($raw) {
            'succeeded'                       => 'succeeded',
            'pending', 'initiated', 'reconciling' => 'pending',
            default                           => 'failed',
        };
    }

    /**
     * Encode (timestamp, id) into an opaque base64 cursor.
     * We deliberately don't use the ID alone because two transactions can
     * share an initiatedAt timestamp.
     */
    private function encodeCursor(string $timestamp, int $id): string
    {
        return base64_encode($timestamp . '|' . $id);
    }

    /**
     * Decode opaque cursor. Returns [null, null] for invalid or empty cursors.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return [null, null];
        }
        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        $ts = $parts[0];
        $id = (int)$parts[1];
        // Light validation — just ensure timestamp parses
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts)) {
            return [null, null];
        }
        return [$ts, $id];
    }
}