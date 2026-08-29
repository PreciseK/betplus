<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Logging\Logger;

/**
 * PasswordChangeService — verifies the player's current password, updates
 * to a new one, and revokes ALL of that player's sessions (including the
 * one making the request).
 *
 * Why revoke the current session too: when a password is changed, every
 * device that had a live session under the old credentials should be
 * forced to re-authenticate. Treating the requesting session as special
 * ("keep me logged in here, log out everyone else") is a real product
 * decision but not the one we made — per spec, all sessions get killed
 * and the user is bounced to login.
 *
 * Audit: writes an entry to eventLog so we can answer "when did this player
 * change their password and from where?" months later, even after Monolog
 * file logs have rotated.
 */
final class PasswordChangeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwords,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{sessionsTerminated: int}
     * @throws HttpException 401 if currentPassword is wrong
     * @throws HttpException 422 if newPassword is the same as currentPassword
     */
    public function change(
        int $playerId,
        string $currentPassword,
        string $newPassword,
        string $ipAddress,
        ?string $userAgent,
    ): array {
        // 1. Look up the player's current hash
        $row = $this->db->fetchOne(
            'SELECT id, msisdn, passwordHash FROM player WHERE id = :id LIMIT 1',
            ['id' => $playerId]
        );

        if ($row === null) {
            // Should never happen — auth middleware verified the session, which
            // implies a valid playerId. If we hit this, the player was deleted
            // mid-request. Treat as auth failure.
            throw new HttpException(401, 'unauthorized', 'Authentication required');
        }

        // 2. Verify the current password. PasswordHasher::verify is constant-time
        //    against the stored hash and rejects empty input.
        if (!$this->passwords->verify($currentPassword, (string)$row['passwordHash'])) {
            $this->logger->info('password_change_failed', [
                'player_id' => $playerId,
                'reason'    => 'bad_current_password',
            ]);
            throw new HttpException(401, 'bad_current_password', 'Current password is incorrect.');
        }

        // 3. Reject no-op (same password). Argon2id is non-deterministic so we
        //    can't compare hashes; use the verify call against the new password.
        if ($this->passwords->verify($newPassword, (string)$row['passwordHash'])) {
            throw new HttpException(422, 'same_password', 'New password must be different from your current one.');
        }

        // 4. Atomic update: set new hash + bump passwordUpdatedAt + terminate
        //    every active session for this player.
        return $this->db->transactional(function (Connection $db) use ($playerId, $newPassword, $ipAddress, $userAgent): array {
            $newHash = $this->passwords->hash($newPassword);

            $db->execute(
                'UPDATE player
                    SET passwordHash = :hash,
                        passwordUpdatedAt = NOW(),
                        updatedAt = NOW()
                 WHERE id = :id',
                ['hash' => $newHash, 'id' => $playerId]
            );

            // Terminate every non-terminated session for this player. We
            // capture the affected count by querying after the update; ROW_COUNT()
            // would also work but is more brittle across drivers.
            $db->execute(
                'UPDATE playerSession
                    SET terminatedAt = NOW()
                 WHERE playerId = :id AND terminatedAt IS NULL',
                ['id' => $playerId]
            );

            $sessionsTerminated = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM playerSession
                  WHERE playerId = :id
                    AND terminatedAt IS NOT NULL
                    AND terminatedAt >= DATE_SUB(NOW(), INTERVAL 5 SECOND)',
                ['id' => $playerId]
            );

            // Audit log entry
            $db->execute(
                'INSERT INTO eventLog (playerId, eventType, ipAddress, userAgent, metadata, createdAt)
                 VALUES (:pid, :type, :ip, :ua, :meta, NOW())',
                [
                    'pid'  => $playerId,
                    'type' => 'password_changed',
                    'ip'   => $ipAddress,
                    'ua'   => $userAgent !== null ? substr($userAgent, 0, 512) : null,
                    'meta' => json_encode(['method' => 'self_service']),
                ]
            );

            $this->logger->info('password_change_succeeded', [
                'player_id' => $playerId,
            ]);

            return ['sessionsTerminated' => $sessionsTerminated];
        });
    }
}