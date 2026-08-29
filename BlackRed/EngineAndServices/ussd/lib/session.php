<?php
/**
 * USSD session repository.
 *
 * One row of ussdSession represents one user dial in progress. We:
 *   - create() on the first turn
 *   - findForUpdate() on subsequent turns (with row lock inside a txn)
 *   - save() at the end of each turn
 *
 * Sessions are not value objects — to keep this slim, we pass associative
 * arrays around. The shape is:
 *
 *   [
 *     'id' => int,
 *     'naloSessionId' => string,
 *     'msisdn' => string,
 *     'network' => string|null,
 *     'playerId' => int|null,
 *     'state' => string,
 *     'data' => array,        // unpacked JSON
 *     'pwRetries' => int,
 *   ]
 *
 * State classes mutate this array directly between turns; index.php calls
 * sessionSave() at the end.
 */

// db() is defined in lib/db.php; caller (index.php / workers) must load it
// before using these functions. Tests stub db() before loading session.php,
// so we explicitly skip require_once here to avoid redeclaration.

/**
 * Create a new session row for the first turn of a dial.
 * Returns the new session array (with id populated).
 */
function sessionCreate(string $naloSessionId, string $msisdn, ?string $network, string $initialState): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO ussdSession (naloSessionId, msisdn, network, state, data, pwRetries)
         VALUES (:sid, :msisdn, :network, :state, '[]', 0)"
    );
    $stmt->execute([
        ':sid'     => $naloSessionId,
        ':msisdn'  => $msisdn,
        ':network' => $network,
        ':state'   => $initialState,
    ]);

    return [
        'id'             => (int) $pdo->lastInsertId(),
        'naloSessionId'  => $naloSessionId,
        'msisdn'         => $msisdn,
        'network'        => $network,
        'playerId'       => null,
        'state'          => $initialState,
        'data'           => [],
        'pwRetries'      => 0,
    ];
}

/**
 * Look up an existing session by Nalo's session ID, locking the row.
 * MUST be called inside a transaction (use dbTxn).
 *
 * Returns the session array, or null if not found (cleaned-up, never existed,
 * etc.).
 */
function sessionFindForUpdate(string $naloSessionId): ?array
{
    $stmt = db()->prepare(
        "SELECT id, naloSessionId, msisdn, network, playerId, state, data, pwRetries
         FROM ussdSession
         WHERE naloSessionId = :sid
         FOR UPDATE"
    );
    $stmt->execute([':sid' => $naloSessionId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $data = $row['data'] !== null ? json_decode((string) $row['data'], true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    return [
        'id'             => (int) $row['id'],
        'naloSessionId'  => (string) $row['naloSessionId'],
        'msisdn'         => (string) $row['msisdn'],
        'network'        => $row['network'] !== null ? (string) $row['network'] : null,
        'playerId'       => $row['playerId'] !== null ? (int) $row['playerId'] : null,
        'state'          => (string) $row['state'],
        'data'           => $data,
        'pwRetries'      => (int) $row['pwRetries'],
    ];
}

/**
 * Persist any mutations made to the session during the turn.
 */
function sessionSave(array $session): void
{
    if (empty($session['id'])) {
        throw new LogicException('sessionSave called on a session without an id');
    }

    $stmt = db()->prepare(
        "UPDATE ussdSession
         SET network = :network,
             playerId = :playerId,
             state = :state,
             data = :data,
             pwRetries = :pwRetries
         WHERE id = :id"
    );
    $stmt->execute([
        ':network'   => $session['network'],
        ':playerId'  => $session['playerId'],
        ':state'     => $session['state'],
        ':data'      => json_encode($session['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':pwRetries' => $session['pwRetries'],
        ':id'        => $session['id'],
    ]);
}

/**
 * Transition the session to a new state. Resets the password retry counter
 * (3 retries is per password-confirm step, not per session).
 */
function sessionTransition(array &$session, string $nextState): void
{
    $session['state'] = $nextState;
    $session['pwRetries'] = 0;
}

/**
 * Garbage-collect old sessions. Called by workers/cleanup.php cron.
 * Returns the number of rows deleted.
 */
function sessionPurgeOlderThan(int $minutes): int
{
    $stmt = db()->prepare(
        "DELETE FROM ussdSession WHERE updatedAt < (NOW() - INTERVAL :mins MINUTE)"
    );
    $stmt->execute([':mins' => $minutes]);
    return $stmt->rowCount();
}
