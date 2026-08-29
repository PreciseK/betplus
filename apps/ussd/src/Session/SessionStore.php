<?php

declare(strict_types=1);

namespace Betplus\Ussd\Session;

/**
 * REQ-USSD-003 designs this as Redis, keyed (msisdn, sessionId), with row-level
 * locking per turn. This interface is Redis-shaped on purpose (get/put/delete by
 * key, an atomic-enough single-writer-per-turn model) so a RedisSessionStore can
 * implement it later with no caller change.
 */
interface SessionStore
{
    public function get(string $sessionId, string $msisdn): ?Session;

    public function put(Session $session): void;

    public function delete(string $sessionId, string $msisdn): void;

    /** REQ-USSD-004 — most recent session for this MSISDN, if any, regardless of sessionId. */
    public function mostRecentFor(string $msisdn): ?Session;

    /** REQ-USSD-005 — purge anything idle beyond $ttlSeconds. @return int number purged */
    public function purgeExpired(int $ttlSeconds): int;
}
