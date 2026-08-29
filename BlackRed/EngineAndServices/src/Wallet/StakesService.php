<?php

declare(strict_types=1);

namespace BlackRed\Wallet;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Logging\Logger;

/**
 * StakesService — read-only feed of game rounds played by a player.
 *
 * Powers the Stake History screen. Returns rows from gameRound, optionally
 * date-filtered (today / this-week / this-month, Africa/Accra timezone),
 * sorted newest-first.
 *
 * What we expose to the player:
 *   - refNumber, gameType, multiplier, colorPicks (parsed to array)
 *   - stake, payout (if win), outcome
 *   - drawnCards (parsed from JSON to {rank, suit, color} objects)
 *   - createdAt (ISO 8601)
 *
 * What we deliberately DO NOT expose:
 *   - enginePath (forced_loss vs fair_random) — internal only
 *   - thresholdPctAtTime, netRevenuePesewas, winsTodayPesewas — internal
 *   - lockedDeck (the full 12-card deck) — not useful to the player
 *   - clientIp, userAgent — security/audit data
 *
 * The omitted columns are forensics for support/admin dashboards, not for
 * end-users. Showing enginePath would tell the player whether the round was
 * predetermined to lose, which is exactly the disclosure we don't make.
 */
final class StakesService
{
    public const FILTER_TODAY = 'today';
    public const FILTER_WEEK  = 'week';
    public const FILTER_MONTH = 'month';

    public const MAX_ROWS = 200;  // hard cap; UI doesn't paginate yet

    public function __construct(
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *   filter: string,
     *   stats: array{played:int, won:int, lost:int, totalStakedPesewas:int, totalWonPesewas:int},
     *   items: list<array{
     *     refNumber:string, gameType:int, multiplier:int,
     *     colorPicks:list<string>, stakePesewas:int,
     *     payoutPesewas:int, outcome:string,
     *     drawnCards:list<array{rank:string,suit:string,color:string}>,
     *     createdAt:string
     *   }>
     * }
     */
    public function listForPlayer(int $playerId, string $filter): array
    {
        $filter = $this->normalizeFilter($filter);

        // Compute date window in Africa/Accra; convert to UTC for the query
        // because gameRound.createdAt is stored in UTC (NOW() in MySQL session
        // is the server's local timezone — but on shared hosting that's
        // typically UTC, and our migrations don't change session tz).
        [$startUtc, $endUtc] = $this->dateWindowUtc($filter);

        $rows = $this->db->fetchAll(
            "SELECT refNumber, gameType, multiplier, colorPicks,
                    stakePesewas, payoutPesewas, outcome,
                    drawnCards, createdAt
               FROM gameRound
              WHERE playerId  = :pid
                AND createdAt >= :start
                AND createdAt <  :end
              ORDER BY id DESC
              LIMIT :lim",
            [
                ':pid'   => $playerId,
                ':start' => $startUtc,
                ':end'   => $endUtc,
                ':lim'   => self::MAX_ROWS,
            ]
        );

        $items = [];
        $stats = [
            'played'             => 0,
            'won'                => 0,
            'lost'               => 0,
            'totalStakedPesewas' => 0,
            'totalWonPesewas'    => 0,
        ];

        foreach ($rows as $r) {
            $picks = $r['colorPicks'] === '' ? [] : explode(',', $r['colorPicks']);
            $drawn = $this->parseDrawnCards((string)$r['drawnCards']);
            $stake = (int)$r['stakePesewas'];
            $payout = (int)$r['payoutPesewas'];
            $outcome = (string)$r['outcome'];

            $items[] = [
                'refNumber'    => (string)$r['refNumber'],
                'gameType'     => (int)$r['gameType'],
                'multiplier'   => (int)$r['multiplier'],
                'colorPicks'   => $picks,
                'stakePesewas' => $stake,
                'payoutPesewas'=> $payout,
                'outcome'      => $outcome,
                'drawnCards'   => $drawn,
                'createdAt'    => $this->isoFromMysqlUtc((string)$r['createdAt']),
            ];

            $stats['played']++;
            $stats['totalStakedPesewas'] += $stake;
            if ($outcome === 'win') {
                $stats['won']++;
                $stats['totalWonPesewas'] += $payout;
            } else {
                $stats['lost']++;
            }
        }

        return [
            'filter' => $filter,
            'stats'  => $stats,
            'items'  => $items,
        ];
    }

    private function normalizeFilter(string $filter): string
    {
        if (!in_array($filter, [self::FILTER_TODAY, self::FILTER_WEEK, self::FILTER_MONTH], true)) {
            throw new HttpException(400, 'invalid_filter',
                'Filter must be one of: today, week, month.');
        }
        return $filter;
    }

    /**
     * Build [startUtc, endUtc] for the given filter, both as MySQL DATETIME
     * strings ('Y-m-d H:i:s'), in UTC. Window boundaries are computed in
     * Africa/Accra, then converted.
     *
     *   today  → 00:00 today (Accra) .. 00:00 tomorrow (Accra)
     *   week   → Monday 00:00 of this week .. start-of-today + 1 day
     *   month  → 1st of this month 00:00 .. start-of-today + 1 day
     */
    private function dateWindowUtc(string $filter): array
    {
        $tz = new \DateTimeZone('Africa/Accra');
        $utc = new \DateTimeZone('UTC');

        $now = new \DateTimeImmutable('now', $tz);
        $startOfToday = $now->setTime(0, 0, 0);
        $endExclusive = $startOfToday->modify('+1 day');

        if ($filter === self::FILTER_TODAY) {
            $start = $startOfToday;
        } elseif ($filter === self::FILTER_WEEK) {
            // Ghana convention — week starts Monday
            $dayOfWeek = (int)$now->format('N'); // 1=Mon..7=Sun
            $start = $startOfToday->modify('-' . ($dayOfWeek - 1) . ' days');
        } else { // month
            $start = $startOfToday->modify('first day of this month');
        }

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $endExclusive->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Parse drawnCards JSON ("7H","KS",...) into [{rank, suit, color}] for
     * the frontend. We keep the heavy lifting on the server so the client
     * doesn't need to know the encoding.
     */
    private function parseDrawnCards(string $json): array
    {
        if ($json === '' || $json === '[]') {
            return [];
        }
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $code) {
            if (!is_string($code) || strlen($code) !== 2) continue;
            $rank = $code[0]; $suitCode = $code[1];
            // Convert API rank code to display ('T' → '10')
            $displayRank = $rank === 'T' ? '10' : $rank;
            $suitMap = ['H' => '♥', 'D' => '♦', 'C' => '♣', 'S' => '♠'];
            if (!isset($suitMap[$suitCode])) continue;
            $color = ($suitCode === 'H' || $suitCode === 'D') ? 'red' : 'black';
            $out[] = [
                'rank'  => $displayRank,
                'suit'  => $suitMap[$suitCode],
                'color' => $color,
            ];
        }
        return $out;
    }

    /**
     * Convert a MySQL DATETIME (assumed UTC) to ISO 8601 with Z suffix.
     * Frontend can then format in user's local time.
     */
    private function isoFromMysqlUtc(string $mysqlDt): string
    {
        try {
            $d = new \DateTimeImmutable($mysqlDt, new \DateTimeZone('UTC'));
            return $d->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $_) {
            return $mysqlDt;
        }
    }
}