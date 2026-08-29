<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Bootstrap\Config;
use BlackRed\Database\Connection;

/**
 * Session manager.
 *
 * Sessions are stored server-side in the `playerSession` table. The cookie
 * sent to the browser carries only an opaque token (32 random bytes,
 * hex-encoded) — no player data, no signed payload. Server-side state means
 * we can revoke sessions instantly and we don't have to worry about
 * cookie-tampering attacks.
 *
 * Two lifetimes apply:
 *
 *   1. Idle (sliding) lifetime — SESSION_LIFETIME, in minutes. Every
 *      authenticated request bumps expiresAt forward by this amount. An
 *      active user stays logged in. An inactive user is eventually expired.
 *
 *   2. Absolute (hard) lifetime — SESSION_MAX_LIFETIME, in minutes. Measured
 *      from createdAt, never extended. Even an active user is forced to
 *      re-authenticate after this elapses. This is the upper bound on how
 *      long a stolen session can be replayed.
 *
 * For BlackRed (mobile-app-like usage with money at stake), defaults are
 * 30 days idle + 90 days max. Tuned via .env.
 *
 * Cookie attributes:
 *   HttpOnly  → JavaScript can't read it (defends against XSS cookie theft)
 *   Secure    → only sent over HTTPS
 *   SameSite=Strict → not sent on cross-site requests (defends against CSRF
 *                     for cookie-based auth — combined with our explicit
 *                     CSRF token middleware on state-changing endpoints,
 *                     defense in depth)
 */
final class SessionManager
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Create a session for a freshly authenticated player. Returns the token.
     *
     * The caller is responsible for setting the cookie on the response (we
     * do that in the controller because we need a Response object).
     *
     * @return array{token: string, sessionId: int, expiresAt: string}
     */
    public function create(int $playerId, string $msisdn, string $channel, string $ip, string $ua): array
    {
        $token = bin2hex(random_bytes(32));
        $lifetimeMin = $this->config->int('SESSION_LIFETIME', 30);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($lifetimeMin * 60));

        $sessionId = $this->db->insert(
            'INSERT INTO playerSession
                (playerId, msisdn, sessionToken, channel, ipAddress, userAgent, expiresAt, createdAt)
             VALUES
                (:pid, :msisdn, :token, :channel, :ip, :ua, :exp, NOW())',
            [
                'pid'     => $playerId,
                'msisdn'  => $msisdn,
                'token'   => $token,
                'channel' => $channel,
                'ip'      => $ip ?: null,
                'ua'      => substr($ua, 0, 500),
                'exp'     => $expiresAt,
            ]
        );

        // Update lastLoginAt for audit purposes
        $this->db->execute(
            'UPDATE player SET lastLoginAt = NOW() WHERE id = :id',
            ['id' => $playerId]
        );

        return [
            'token'     => $token,
            'sessionId' => $sessionId,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Look up a session by token. Returns the row if valid (not expired,
     * not terminated), null otherwise.
     *
     * On successful lookup, slides the expiry forward. This is what makes
     * an active session "stay alive" automatically.
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id, playerId, msisdn, channel, expiresAt, terminatedAt, createdAt
             FROM playerSession
             WHERE sessionToken = :token LIMIT 1',
            ['token' => $token]
        );

        if ($row === null) {
            return null;
        }
        if ($row['terminatedAt'] !== null) {
            return null;
        }
        if (strtotime((string)$row['expiresAt']) < time()) {
            return null;
        }

        // Hard ceiling: regardless of activity, sessions cannot live past
        // createdAt + SESSION_MAX_LIFETIME. Defends against stolen sessions
        // being kept alive indefinitely by automated heartbeat traffic.
        $maxLifetimeMin = $this->config->int('SESSION_MAX_LIFETIME', 129600); // 90 days default
        $hardExpiry = strtotime((string)$row['createdAt']) + ($maxLifetimeMin * 60);
        if ($hardExpiry < time()) {
            // Session has aged out beyond its absolute limit. Mark it
            // terminated so future requests with this token short-circuit
            // at the terminatedAt check instead of redoing this calculation.
            $this->db->execute(
                'UPDATE playerSession SET terminatedAt = NOW() WHERE id = :id AND terminatedAt IS NULL',
                ['id' => $row['id']]
            );
            return null;
        }

        // Sliding idle lifetime. Don't re-write if it's within 1 minute of
        // current expiry — saves a write per request for chatty clients.
        $lifetimeMin = $this->config->int('SESSION_LIFETIME', 30);
        $newExpires = gmdate('Y-m-d H:i:s', time() + ($lifetimeMin * 60));
        if (strtotime((string)$row['expiresAt']) - time() < ($lifetimeMin * 60 - 60)) {
            // But never push the sliding expiry past the hard ceiling.
            $newExpiresTime = min(time() + ($lifetimeMin * 60), $hardExpiry);
            $newExpires = gmdate('Y-m-d H:i:s', $newExpiresTime);
            $this->db->execute(
                'UPDATE playerSession SET expiresAt = :exp WHERE id = :id',
                ['exp' => $newExpires, 'id' => $row['id']]
            );
            $row['expiresAt'] = $newExpires;
        }

        return $row;
    }

    /**
     * Terminate a session. Idempotent — calling on an already-terminated
     * session is a no-op. Used by logout.
     */
    public function terminate(int $sessionId): void
    {
        $this->db->execute(
            'UPDATE playerSession SET terminatedAt = NOW() WHERE id = :id AND terminatedAt IS NULL',
            ['id' => $sessionId]
        );
    }

    /**
     * Build the Set-Cookie header value for a session token.
     *
     * Use cases:
     *   - On login: $token from create(), $expiresAt from create()
     *   - On logout: pass token='' and expiresAt at epoch to clear the cookie
     */
    public function buildCookieHeader(string $token, ?string $expiresAt): string
    {
        $name = $this->config->string('SESSION_COOKIE_NAME', 'BR_SESSION');
        $secure = $this->config->bool('SESSION_COOKIE_SECURE', true);
        $httpOnly = $this->config->bool('SESSION_COOKIE_HTTPONLY', true);
        $sameSite = $this->config->string('SESSION_COOKIE_SAMESITE', 'Strict');

        $parts = [$name . '=' . $token];
        $parts[] = 'Path=/';

        if ($expiresAt !== null) {
            // Cookie format wants RFC 7231 GMT date.
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', strtotime($expiresAt)) . ' GMT';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        $parts[] = 'SameSite=' . $sameSite;

        return implode('; ', $parts);
    }
}