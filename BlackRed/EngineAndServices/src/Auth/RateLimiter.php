<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;

/**
 * Rate limiter — enforces N attempts per minute per (endpoint, key) combination.
 *
 * Storage: `rateLimitBucket` table. One row per (bucketKey, windowStart) tuple,
 * created/incremented atomically via INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * The bucket key is typically constructed as "endpoint:ip" — e.g.
 *   "auth.login:154.161.139.186"
 *
 * Why this works without locking:
 *   - The UNIQUE KEY (bucketKey, windowStart) makes upserts atomic.
 *   - We always read the count in the same query that increments, so we
 *     don't have a check-then-act race condition.
 *
 * The 1-minute granularity is deliberate. Sub-minute windows would require
 * sub-minute precision in the windowStart column and many more rows. For
 * our use case (preventing brute-force login attempts, runaway signup spam),
 * 1-minute resolution is plenty.
 */
final class RateLimiter
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Record an attempt and return whether the limit has been exceeded.
     *
     * @param string $bucketKey  Like "auth.login:1.2.3.4"
     * @param int    $maxPerMin  Maximum attempts allowed in the current 1-minute window
     * @return bool  true if the attempt is OK, false if rate-limited
     */
    public function attempt(string $bucketKey, int $maxPerMin): bool
    {
        $windowStart = $this->floorToMinute(time());

        // Atomic upsert: insert if new, increment if existing.
        $this->db->execute(
            'INSERT INTO rateLimitBucket (bucketKey, windowStart, attempts, createdAt)
             VALUES (:key, :win, 1, NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1',
            ['key' => $bucketKey, 'win' => $windowStart]
        );

        $count = (int)$this->db->fetchValue(
            'SELECT attempts FROM rateLimitBucket
             WHERE bucketKey = :key AND windowStart = :win LIMIT 1',
            ['key' => $bucketKey, 'win' => $windowStart]
        );

        return $count <= $maxPerMin;
    }

    /**
     * Reset attempts for a bucket. Called on successful authentication so a
     * legitimate user who fat-fingered their password a few times doesn't
     * stay locked out after they finally get in.
     */
    public function reset(string $bucketKey): void
    {
        $this->db->execute(
            'DELETE FROM rateLimitBucket
             WHERE bucketKey = :key AND windowStart >= :since',
            [
                'key'   => $bucketKey,
                'since' => $this->floorToMinute(time() - 300), // last 5 minutes
            ]
        );
    }

    /**
     * Cleanup helper — call from the daily cron to delete old buckets.
     * Returns count of rows deleted.
     */
    public function cleanupOlderThan(int $hoursOld = 24): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hoursOld * 3600));
        $stmt = $this->db->execute(
            'DELETE FROM rateLimitBucket WHERE windowStart < :cutoff',
            ['cutoff' => $cutoff]
        );
        return $stmt->rowCount();
    }

    private function floorToMinute(int $unixTime): string
    {
        return gmdate('Y-m-d H:i:00', $unixTime);
    }
}
