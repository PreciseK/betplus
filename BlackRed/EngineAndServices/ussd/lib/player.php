<?php
/**
 * Player + wallet lookup helpers.
 *
 * USSD-side reads only. Writes (registration) come in PR 3 with their own
 * helpers in this file.
 *
 * All MSISDN parameters here are in CANONICAL form (233XXXXXXXXX) — see
 * lib/msisdn.php for normalisation. The DB stores canonical form too, so
 * we don't translate at the lookup boundary.
 *
 * Returns simple associative arrays. We don't need value objects for a
 * 950-LOC app.
 */

// Note: this file uses db() which is defined in lib/db.php. We do NOT
// require_once db.php here because tests stub db() before load — a
// require_once would either redeclare (fatal) or skip the stub (broken).
// All entry points (index.php, workers/*.php) load db.php explicitly.

/**
 * Find a player by ID. Used by deposit/withdraw flows where we already
 * know the player from the session and just need their current details.
 */
function playerById(int $playerId): ?array
{
    $stmt = db()->prepare(
        "SELECT id, msisdn, paymentProvider, registeredName, displayName,
                accountStatus, passwordHash, deletedAt
         FROM player
         WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $playerId]);
    $row = $stmt->fetch();
    if ($row === false) return null;

    return [
        'id'              => (int)$row['id'],
        'msisdn'          => (string)$row['msisdn'],
        'paymentProvider' => (string)$row['paymentProvider'],
        'registeredName'  => (string)$row['registeredName'],
        'displayName'     => $row['displayName'] !== null ? (string)$row['displayName'] : null,
        'accountStatus'   => (string)$row['accountStatus'],
        'passwordHash'    => $row['passwordHash'] !== null ? (string)$row['passwordHash'] : null,
        'deletedAt'       => $row['deletedAt'] !== null ? (string)$row['deletedAt'] : null,
    ];
}

/**
 * Find a player by MSISDN. Returns null if not registered or soft-deleted.
 *
 * @return array{
 *   id: int,
 *   msisdn: string,
 *   paymentProvider: string,
 *   registeredName: string,
 *   displayName: ?string,
 *   accountStatus: string,
 *   passwordHash: ?string
 * }|null
 */
function playerByMsisdn(string $canonicalMsisdn): ?array
{
    $stmt = db()->prepare(
        "SELECT id, msisdn, paymentProvider, registeredName, displayName,
                accountStatus, passwordHash
         FROM player
         WHERE msisdn = :m AND deletedAt IS NULL
         LIMIT 1"
    );
    $stmt->execute([':m' => $canonicalMsisdn]);
    $row = $stmt->fetch();
    if ($row === false) return null;

    return [
        'id'              => (int) $row['id'],
        'msisdn'          => (string) $row['msisdn'],
        'paymentProvider' => (string) $row['paymentProvider'],
        'registeredName'  => (string) $row['registeredName'],
        'displayName'     => $row['displayName'] !== null ? (string) $row['displayName'] : null,
        'accountStatus'   => (string) $row['accountStatus'],
        'passwordHash'    => $row['passwordHash'] !== null ? (string) $row['passwordHash'] : null,
    ];
}

/**
 * First name only, for friendly menu text like "Hello Kwame".
 * Splits on whitespace, returns the first token, title-cased.
 */
function playerFirstName(array $player): string
{
    $name = $player['displayName'] ?: $player['registeredName'];
    $first = trim(strtok($name, ' '));
    if ($first === '' || $first === false) {
        return 'there';  // safe fallback if name is empty/weird
    }
    // Title-case (handles ALL CAPS names from MoMo lookups)
    return ucfirst(strtolower($first));
}

/**
 * Read both wallet balances for a player. Returns [playPesewas, payoutPesewas].
 * Throws if either wallet is missing (should never happen — every player
 * gets both wallets at registration).
 *
 * @return array{play: int, payout: int}
 */
function playerBalances(int $playerId): array
{
    $rows = db()->prepare(
        "SELECT walletType, cachedBalancePesewas
         FROM wallet
         WHERE playerId = :pid AND status = 'active'"
    );
    $rows->execute([':pid' => $playerId]);
    $found = ['PLAY' => null, 'PAYOUT' => null];
    while ($row = $rows->fetch()) {
        $type = (string) $row['walletType'];
        // array_key_exists, not isset — null values fail isset()
        if (array_key_exists($type, $found)) {
            $found[$type] = (int) $row['cachedBalancePesewas'];
        }
    }
    if ($found['PLAY'] === null || $found['PAYOUT'] === null) {
        throw new RuntimeException("Player $playerId is missing PLAY or PAYOUT wallet");
    }
    return ['play' => $found['PLAY'], 'payout' => $found['PAYOUT']];
}

/**
 * Format pesewas as "GHS X.YY" for screen display.
 *
 *      0 → "GHS 0.00"
 *    100 → "GHS 1.00"
 *  12345 → "GHS 123.45"
 */
function formatPesewas(int $pesewas): string
{
    $abs = abs($pesewas);
    $major = intdiv($abs, 100);
    $minor = $abs % 100;
    $sign = $pesewas < 0 ? '-' : '';
    return sprintf('GHS %s%d.%02d', $sign, $major, $minor);
}

/**
 * Fetch the most recent gameRound for a player, or null if they've never played.
 *
 * @return array{
 *   refNumber: string,
 *   gameType: int,
 *   multiplier: int,
 *   colorPicks: string,
 *   stakePesewas: int,
 *   outcome: string,
 *   payoutPesewas: int,
 *   drawnCards: ?string,
 *   createdAt: string
 * }|null
 */
function playerLastStake(int $playerId): ?array
{
    $stmt = db()->prepare(
        "SELECT refNumber, gameType, multiplier, colorPicks, stakePesewas,
                outcome, payoutPesewas, drawnCards, createdAt
         FROM gameRound
         WHERE playerId = :pid
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':pid' => $playerId]);
    $row = $stmt->fetch();
    if ($row === false) return null;

    return [
        'refNumber'     => (string) $row['refNumber'],
        'gameType'      => (int) $row['gameType'],
        'multiplier'    => (int) $row['multiplier'],
        'colorPicks'    => (string) $row['colorPicks'],
        'stakePesewas'  => (int) $row['stakePesewas'],
        'outcome'       => (string) $row['outcome'],
        'payoutPesewas' => (int) $row['payoutPesewas'],
        'drawnCards'    => $row['drawnCards'] !== null ? (string) $row['drawnCards'] : null,
        'createdAt'     => (string) $row['createdAt'],
    ];
}

/**
 * Atomically create a new player + 2 accounts (PLAY/PAYOUT) + 2 wallets.
 *
 * Mirrors the web app's SignupService::createPlayer() exactly so users
 * registered via USSD are indistinguishable from web-registered users.
 *
 * Differences from web:
 *   - registrationChannel = 'ussd' (vs web's 'web')
 *   - kycStatus = 'pending' (same default; USSD doesn't do KYC yet)
 *   - accountStatus = 'active' (same default)
 *
 * Throws on UNIQUE violation (race: two USSD turns trying to register the
 * same MSISDN simultaneously). The caller (RegSetPasswordState) catches
 * this and routes the user to the main menu since they're now registered.
 *
 * @return int  The new player ID
 */
function playerCreate(
    string $canonicalMsisdn,
    string $provider,         // 'MTN' | 'ATL' | 'TEL'
    string $registeredName,
    string $passwordHash
): int {
    return dbTxn(function (PDO $pdo) use ($canonicalMsisdn, $provider, $registeredName, $passwordHash): int {
        // 1. Insert player
        $stmt = $pdo->prepare(
            "INSERT INTO player
                (msisdn, paymentProvider, registeredName, passwordHash, email,
                 kycStatus, accountStatus, registrationChannel, createdAt, updatedAt)
             VALUES
                (:msisdn, :prov, :name, :phash, NULL,
                 'pending', 'active', 'ussd', NOW(), NOW())"
        );
        $stmt->execute([
            ':msisdn' => $canonicalMsisdn,
            ':prov'   => $provider,
            ':name'   => $registeredName,
            ':phash'  => $passwordHash,
        ]);
        $playerId = (int)$pdo->lastInsertId();

        // 2. Insert PLAY account (credit-normal — player wallets are house liabilities)
        $stmt = $pdo->prepare(
            "INSERT INTO account
                (accountCode, accountType, ownerType, ownerId, currency,
                 normalBalance, status, createdAt, updatedAt)
             VALUES
                (:code, 'PLAYER_PLAY', 'player', :ownerId, 'GHS',
                 'credit', 'active', NOW(), NOW())"
        );
        $stmt->execute([
            ':code'    => 'PLAYER_PLAY:' . $playerId,
            ':ownerId' => $playerId,
        ]);
        $playAccountId = (int)$pdo->lastInsertId();

        // 3. Insert PAYOUT account
        $stmt = $pdo->prepare(
            "INSERT INTO account
                (accountCode, accountType, ownerType, ownerId, currency,
                 normalBalance, status, createdAt, updatedAt)
             VALUES
                (:code, 'PLAYER_PAYOUT', 'player', :ownerId, 'GHS',
                 'credit', 'active', NOW(), NOW())"
        );
        $stmt->execute([
            ':code'    => 'PLAYER_PAYOUT:' . $playerId,
            ':ownerId' => $playerId,
        ]);
        $payoutAccountId = (int)$pdo->lastInsertId();

        // 4. Insert PLAY wallet
        $stmt = $pdo->prepare(
            "INSERT INTO wallet
                (playerId, walletType, accountId, cachedBalancePesewas, version,
                 status, createdAt, updatedAt)
             VALUES
                (:pid, 'PLAY', :aid, 0, 0, 'active', NOW(), NOW())"
        );
        $stmt->execute([':pid' => $playerId, ':aid' => $playAccountId]);

        // 5. Insert PAYOUT wallet
        $stmt = $pdo->prepare(
            "INSERT INTO wallet
                (playerId, walletType, accountId, cachedBalancePesewas, version,
                 status, createdAt, updatedAt)
             VALUES
                (:pid, 'PAYOUT', :aid, 0, 0, 'active', NOW(), NOW())"
        );
        $stmt->execute([':pid' => $playerId, ':aid' => $payoutAccountId]);

        return $playerId;
    });
}


/**
 * Per-account lockout helpers (shared with the web app via the new
 * player.failedLoginCount + player.lockedUntil columns).
 *
 * Policy: 3 wrong PIN attempts in any channel (web OR USSD) locks the
 * account for 15 minutes. A successful PIN verification resets the
 * counter to zero. After lockout expires, fail count resets so the user
 * starts fresh again.
 *
 * Why share with the web app: a 4-digit PIN has only 10,000 possible
 * values. Without persistent lockout an attacker can brute-force the
 * space across channels (try 3 on web, locked there, jump to USSD,
 * try 3 more, etc.). The shared pool closes that gap.
 */

/**
 * Check whether the player is currently locked out.
 *
 * Returns NULL if not locked. Returns the number of minutes remaining
 * (rounded up, minimum 1) if locked. The caller is responsible for
 * showing a user-facing message and ending the USSD session.
 */
function playerLockoutCheck(int $playerId): ?int
{
    $stmt = db()->prepare(
        "SELECT lockedUntil
         FROM player
         WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $playerId]);
    $row = $stmt->fetch();
    if ($row === false || $row['lockedUntil'] === null) {
        return null;
    }

    $unlockAtTs = strtotime((string)$row['lockedUntil']);
    if ($unlockAtTs === false || $unlockAtTs <= time()) {
        // Lock window already elapsed — leave the column alone (next
        // wrong attempt will overwrite it; correct attempt will clear it).
        return null;
    }

    return max(1, (int)ceil(($unlockAtTs - time()) / 60));
}

/**
 * Record one failed PIN attempt. If this hits 3 strikes, set lockedUntil
 * to NOW() + 15 minutes. Returns the resulting fail count.
 *
 * This is the only place that writes to lockedUntil. Keep it that way
 * so the lockout duration stays consistent across channels.
 */
function playerLockoutBump(int $playerId): int
{
    return dbTxn(function (PDO $pdo) use ($playerId): int {
        // Read current state with row lock to avoid lost updates if the
        // user is hammering simultaneously from web and USSD. FOR UPDATE
        // is MariaDB syntax; SQLite (test fixture) doesn't support it.
        $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $lockSql = $isSqlite ? '' : ' FOR UPDATE';

        $stmt = $pdo->prepare(
            "SELECT failedLoginCount
             FROM player
             WHERE id = :id"
            . $lockSql
        );
        $stmt->execute([':id' => $playerId]);
        $row = $stmt->fetch();
        if ($row === false) {
            // Player vanished between fetch and update — nothing to bump.
            return 0;
        }

        $newCount = (int)$row['failedLoginCount'] + 1;

        if ($newCount >= 3) {
            // Trip the lock.
            if ($isSqlite) {
                // SQLite has different date arithmetic syntax.
                $upd = $pdo->prepare(
                    "UPDATE player
                     SET failedLoginCount = :c,
                         lockedUntil = datetime('now', '+15 minutes')
                     WHERE id = :id"
                );
            } else {
                $upd = $pdo->prepare(
                    "UPDATE player
                     SET failedLoginCount = :c,
                         lockedUntil = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                     WHERE id = :id"
                );
            }
            $upd->execute([':c' => $newCount, ':id' => $playerId]);
        } else {
            $upd = $pdo->prepare(
                "UPDATE player
                 SET failedLoginCount = :c
                 WHERE id = :id"
            );
            $upd->execute([':c' => $newCount, ':id' => $playerId]);
        }

        return $newCount;
    });
}

/**
 * Reset lockout state on successful PIN verification. Clears both the
 * fail counter and any lock timestamp.
 *
 * Idempotent — safe to call even when the player has fail count 0 and
 * no lock set; the UPDATE is a no-op in that case.
 */
function playerLockoutReset(int $playerId): void
{
    $stmt = db()->prepare(
        "UPDATE player
         SET failedLoginCount = 0,
             lockedUntil = NULL
         WHERE id = :id AND (failedLoginCount > 0 OR lockedUntil IS NOT NULL)"
    );
    $stmt->execute([':id' => $playerId]);
}
