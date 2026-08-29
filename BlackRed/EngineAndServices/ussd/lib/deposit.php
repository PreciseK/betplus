<?php
/**
 * Deposit business logic.
 *
 * Three phases of a deposit's life:
 *
 *   PHASE 1 — depositPreflight()                   [in USSD turn]
 *     - Validates amount range
 *     - Rejects if another deposit is pending
 *     - Inserts depositRequest row (status='initiated')
 *     - Records the request payload we WILL send to ANM
 *     - Returns the depositId
 *     - DOES NOT call ANM yet
 *
 *   PHASE 2 — depositFireAnm()                     [post-response, +3s delay]
 *     - Calls anmInitiateCtm()
 *     - On accepted: marks row 'pending' — ANM will push PIN prompt to user
 *     - On rejected/network-error: marks 'failed', sends apology SMS
 *
 *   PHASE 3 — depositSettleSuccess/Failure()       [later, from ANM callback]
 *     - Called when ANM POSTs the outcome to /callbacks/momo.php
 *     - On success: atomic credit pipeline (walletTxn + 3 ledger entries +
 *       wallet balance update)
 *     - Sends Hubtel SMS to the player either way
 *     - Idempotent (multiple ANM retries safe)
 *
 * Why phase 1+2 are split:
 *   ANM/MoMo can't push a PIN prompt to a handset that's still in an
 *   active USSD session. We must respond to Nalo first (closing the
 *   user's USSD session on their phone), wait 3 seconds for the close
 *   to propagate, THEN ask ANM to send the PIN. Splitting preflight
 *   from fire-anm lets us put the ANM call in a post-response phase.
 *
 *   The preflight row exists from the moment the user taps Confirm —
 *   even if our PHP process dies between phase 1 and phase 2, we have
 *   a record. Recon cron (PR 7) will mop up any stuck-in-initiated rows.
 */

const DEPOSIT_VALIDITY_MINUTES = 15;
const DEPOSIT_FEE_BPS = 100;  // 1% — matches web app's DEPOSIT_FEE_BPS

/**
 * Phase 1 — validate, insert row, return id. No ANM call yet.
 *
 * @return array{depositId: int, exttrid: string, amountPesewas: int}
 *
 * @throws InvalidArgumentException on amount range violation
 * @throws RuntimeException on player ineligibility / pending deposit
 */
function depositPreflight(int $playerId, int $amountPesewas, string $clientIp): array
{
    global $USSD_CONFIG;

    $minPesewas = (int)($USSD_CONFIG['deposit']['min_pesewas'] ?? 200);
    $maxPesewas = (int)($USSD_CONFIG['deposit']['max_pesewas'] ?? 500000);

    if ($amountPesewas < $minPesewas) {
        throw new InvalidArgumentException(
            sprintf('Minimum deposit is GHS %s.', formatPesewas($minPesewas))
        );
    }
    if ($amountPesewas > $maxPesewas) {
        throw new InvalidArgumentException(
            sprintf('Maximum deposit is GHS %s.', formatPesewas($maxPesewas))
        );
    }

    $player = playerById($playerId);
    if ($player === null
        || $player['accountStatus'] !== 'active'
        || ($player['deletedAt'] ?? null) !== null
    ) {
        throw new RuntimeException("Account not eligible for deposits.");
    }

    // Reject if another deposit is already in flight
    $stmt = db()->prepare(
        "SELECT refNumber FROM depositRequest
          WHERE playerId = :p
            AND status IN ('initiated','pending')
            AND expiresAt > NOW()
          LIMIT 1"
    );
    $stmt->execute([':p' => $playerId]);
    if ($stmt->fetch() !== false) {
        throw new RuntimeException(
            "You have a deposit waiting. Approve the MoMo prompt or wait a few minutes."
        );
    }

    // 16 hex = 64 bits of entropy, ≤20 chars (ANM exttrid limit)
    $exttrid = bin2hex(random_bytes(8));

    $msisdn   = (string)$player['msisdn'];
    $provider = (string)$player['paymentProvider'];

    // Insert depositRequest with status='initiated'. Status moves to 'pending'
    // after ANM accepts in phase 2.
    //
    // expiresAt computed in PHP rather than DATE_ADD(NOW(), INTERVAL N MINUTE)
    // so the SQL is portable to SQLite (tests) and MariaDB (prod).
    $expiresAt = gmdate('Y-m-d H:i:s', time() + DEPOSIT_VALIDITY_MINUTES * 60);

    $stmt = db()->prepare(
        "INSERT INTO depositRequest
            (refNumber, playerId, msisdn, paymentProvider, amountPesewas,
             status, channel, initiatedAt, expiresAt, clientIp)
         VALUES
            (:ref, :pid, :msisdn, :prov, :amt,
             'initiated', 'ussd', NOW(), :exp, :ip)"
    );
    $stmt->execute([
        ':ref'    => $exttrid,
        ':pid'    => $playerId,
        ':msisdn' => $msisdn,
        ':prov'   => $provider,
        ':amt'    => $amountPesewas,
        ':exp'    => $expiresAt,
        ':ip'     => $clientIp,
    ]);
    $depositId = (int)db()->lastInsertId();

    ussdLog('DEP_PREFLIGHT_OK', [
        'depositId' => $depositId,
        'exttrid'   => $exttrid,
        'playerId'  => $playerId,
        'amount'    => $amountPesewas,
        'provider'  => $provider,
    ]);

    return [
        'depositId'     => $depositId,
        'exttrid'       => $exttrid,
        'amountPesewas' => $amountPesewas,
    ];
}

/**
 * Phase 2 — fire the ANM CTM call.
 *
 * Called from index.php AFTER the USSD response has been sent to Nalo
 * and (per ANM constraints) a 3-second wait has passed for the handset's
 * USSD session to fully close.
 *
 * Reads the row created in phase 1 by id. If the row has been moved out
 * of 'initiated' (defensive — shouldn't happen but recon cron might race),
 * we bail. Otherwise: call ANM, update row to 'pending' on accept or
 * 'failed' on reject/error, send apology SMS on failure.
 *
 * Returns nothing — this is fire-and-forget from the caller's POV.
 */
function depositFireAnm(int $depositId): void
{
    global $USSD_CONFIG;

    try {
        $stmt = db()->prepare(
            "SELECT id, refNumber, playerId, msisdn, paymentProvider,
                    amountPesewas, status
             FROM depositRequest
             WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $depositId]);
        $deposit = $stmt->fetch();

        if ($deposit === false) {
            ussdLog('DEP_FIRE_ROW_MISSING', ['depositId' => $depositId]);
            return;
        }
        if ($deposit['status'] !== 'initiated') {
            ussdLog('DEP_FIRE_BAD_STATUS', [
                'depositId' => $depositId,
                'status'    => $deposit['status'],
            ]);
            return;
        }

        $exttrid       = (string)$deposit['refNumber'];
        $playerId      = (int)$deposit['playerId'];
        $msisdn        = (string)$deposit['msisdn'];
        $provider      = (string)$deposit['paymentProvider'];
        $amountPesewas = (int)$deposit['amountPesewas'];

        $phoneLocal  = displayMsisdn($msisdn);
        $bankCode    = anmBankCodeFromProvider($provider);
        $amountGhs   = sprintf('%d.%02d', intdiv($amountPesewas, 100), $amountPesewas % 100);
        $reference   = (string)($USSD_CONFIG['deposit']['reference'] ?? 'BlackRed');
        $callbackUrl = (string)($USSD_CONFIG['anm']['callback_url'] ?? '');

        // Stash the outgoing request payload BEFORE the call so we have it
        // even if ANM hangs us.
        db()->prepare(
            "UPDATE depositRequest SET momoRequestPayload = :req WHERE id = :id"
        )->execute([
            ':req' => json_encode([
                'exttrid'      => $exttrid,
                'phone_local'  => $phoneLocal,
                'bank_code'    => $bankCode,
                'amount'       => $amountGhs,
                'reference'    => substr($reference, 0, 10),
                'callback_url' => $callbackUrl,
            ]),
            ':id'  => $depositId,
        ]);

        // Call ANM
        try {
            $result = anmInitiateCtm(
                $exttrid, $phoneLocal, $bankCode, $amountGhs, $reference, $callbackUrl
            );
        } catch (RuntimeException $e) {
            // Network / parse failure — mark failed, send apology SMS
            db()->prepare(
                "UPDATE depositRequest
                    SET status = 'failed',
                        failureCode = 'network_error',
                        failureReason = :reason,
                        confirmedAt = NOW()
                  WHERE id = :id"
            )->execute([
                ':reason' => substr($e->getMessage(), 0, 500),
                ':id'     => $depositId,
            ]);
            ussdLog('DEP_FIRE_NETWORK_ERROR', [
                'depositId' => $depositId,
                'error'     => $e->getMessage(),
            ]);
            depositSendFailureSms($msisdn, $amountPesewas,
                'Could not reach MoMo provider. Please try again.');
            return;
        }

        if ($result['accepted']) {
            db()->prepare(
                "UPDATE depositRequest
                    SET status = 'pending',
                        momoResponsePayload = :resp
                  WHERE id = :id AND status = 'initiated'"
            )->execute([
                ':resp' => json_encode($result['raw']),
                ':id'   => $depositId,
            ]);
            ussdLog('DEP_FIRE_ACCEPTED', [
                'depositId' => $depositId,
                'exttrid'   => $exttrid,
            ]);
            return;
        }

        // ANM rejected
        $reason = $result['respDesc'] ?? 'MoMo declined the request.';
        db()->prepare(
            "UPDATE depositRequest
                SET status = 'failed',
                    failureCode = :code,
                    failureReason = :reason,
                    momoResponsePayload = :resp,
                    confirmedAt = NOW()
              WHERE id = :id AND status = 'initiated'"
        )->execute([
            ':code'   => substr($result['respCode'] ?? 'unknown', 0, 50),
            ':reason' => substr($reason, 0, 500),
            ':resp'   => json_encode($result['raw']),
            ':id'     => $depositId,
        ]);
        ussdLog('DEP_FIRE_REJECTED', [
            'depositId' => $depositId,
            'exttrid'   => $exttrid,
            'respCode'  => $result['respCode'],
        ]);
        depositSendFailureSms($msisdn, $amountPesewas, $reason);

    } catch (Throwable $e) {
        // Catch-all — we're past the USSD session, errors here are silent
        // unless we log them.
        ussdLog('DEP_FIRE_UNEXPECTED', [
            'depositId' => $depositId,
            'error'     => $e->getMessage(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }
}

/**
 * Send an SMS to the user when an initiation failure happens after the
 * USSD session has already closed.
 *
 * Best-effort — never throws.
 */
function depositSendFailureSms(string $msisdn, int $amountPesewas, string $reason): void
{
    try {
        $msg = sprintf(
            "BlackRed: Your %s deposit could not be processed. %s Please try again.",
            formatPesewas($amountPesewas),
            substr($reason, 0, 100)
        );
        hubtelSendSms($msisdn, $msg);
    } catch (Throwable $e) {
        ussdLog('DEP_FAIL_SMS_ERROR', ['error' => $e->getMessage()]);
    }
}

/**
 * Map paymentProvider (schema) → bank_code (ANM).
 *   MTN → MTN, TEL → VOD, ATL → AIR
 */
function anmBankCodeFromProvider(string $provider): string
{
    return match (strtoupper($provider)) {
        'MTN' => 'MTN',
        'TEL' => 'VOD',
        'ATL' => 'AIR',
        default => throw new InvalidArgumentException("Unknown provider: $provider"),
    };
}

/**
 * Phase 3a — settle a deposit on successful ANM callback.
 *
 * Idempotent. Returns true if we processed the callback (whether or not we
 * did work this time). False if not found / expired.
 *
 * Atomic: walletTransaction + 3 ledgerEntries + wallet balance update are
 * in one DB transaction. Any failure rolls back the whole thing.
 */
function depositSettleSuccess(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
{
    return dbTxn(function (PDO $pdo) use ($exttrid, $transStatus, $callbackIp, $rawBody): bool {
        $stmt = $pdo->prepare(
            "SELECT id, playerId, msisdn, paymentProvider, amountPesewas,
                    status, refNumber, expiresAt
             FROM depositRequest
             WHERE refNumber = :ref
             FOR UPDATE"
        );
        $stmt->execute([':ref' => $exttrid]);
        $deposit = $stmt->fetch();

        if ($deposit === false) {
            ussdLog('DEP_CB_UNKNOWN_REF', ['exttrid' => $exttrid, 'ip' => $callbackIp]);
            return false;
        }

        if ($deposit['expiresAt'] !== null && strtotime((string)$deposit['expiresAt']) < time()) {
            ussdLog('DEP_CB_EXPIRED', ['exttrid' => $exttrid, 'depositId' => $deposit['id']]);
            return false;
        }

        // Idempotency
        if (!in_array($deposit['status'], ['initiated', 'pending'], true)) {
            ussdLog('DEP_CB_ALREADY_PROCESSED', [
                'depositId' => $deposit['id'],
                'status'    => $deposit['status'],
            ]);
            return true;
        }

        $depositId     = (int)$deposit['id'];
        $playerId      = (int)$deposit['playerId'];
        $amountPesewas = (int)$deposit['amountPesewas'];
        $provider      = (string)$deposit['paymentProvider'];

        // Look up the three accounts we'll move money through
        $stmt = $pdo->prepare(
            "SELECT id FROM account
              WHERE accountType = 'PLAYER_PLAY' AND ownerType = 'player' AND ownerId = :pid
              LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId]);
        $playAccount = $stmt->fetch();
        if ($playAccount === false) {
            throw new RuntimeException("PLAYER_PLAY account missing for player $playerId");
        }
        $playAccountId = (int)$playAccount['id'];

        $floatType = 'MOMO_FLOAT_' . $provider;
        $stmt = $pdo->prepare(
            "SELECT id FROM account WHERE accountType = :t AND ownerType = 'momo' LIMIT 1"
        );
        $stmt->execute([':t' => $floatType]);
        $floatAccount = $stmt->fetch();
        if ($floatAccount === false) {
            throw new RuntimeException("$floatType account not seeded");
        }
        $floatAccountId = (int)$floatAccount['id'];

        $stmt = $pdo->prepare(
            "SELECT id FROM account WHERE accountCode = 'MOMO_FEE_EXPENSE' LIMIT 1"
        );
        $stmt->execute();
        $feeAccount = $stmt->fetch();
        if ($feeAccount === false) {
            throw new RuntimeException('MOMO_FEE_EXPENSE account not seeded');
        }
        $feeAccountId = (int)$feeAccount['id'];

        // Race-loss check via conditional UPDATE
        $stmt = $pdo->prepare(
            "UPDATE depositRequest
                SET status = 'succeeded',
                    callbackIp = :ip,
                    confirmedAt = NOW()
              WHERE id = :id AND status IN ('initiated','pending')"
        );
        $stmt->execute([':id' => $depositId, ':ip' => $callbackIp]);
        if ($stmt->rowCount() === 0) {
            ussdLog('DEP_CB_RACE_LOST', ['depositId' => $depositId]);
            return true;
        }

        // walletTransaction (refNumber = exttrid; UNIQUE, retry would throw)
        $stmt = $pdo->prepare(
            "INSERT INTO walletTransaction
                (refNumber, txnType, playerId, amountPesewas, currency,
                 status, metadata, initiatedBy, channel, createdAt, completedAt)
             VALUES
                (:ref, 'DEPOSIT', :pid, :amt, 'GHS',
                 'completed', :meta, 'player', 'ussd', NOW(), NOW())"
        );
        $stmt->execute([
            ':ref'  => $exttrid,
            ':pid'  => $playerId,
            ':amt'  => $amountPesewas,
            ':meta' => json_encode([
                'provider'   => $provider,
                'msisdn'     => $deposit['msisdn'],
                'anmStatus'  => $transStatus,
                'callbackAt' => gmdate('c'),
            ]),
        ]);
        $walletTxnId = (int)$pdo->lastInsertId();

        // Fee computation. Player gets full amount; ledger tracks the 1%
        // as MOMO_FEE_EXPENSE (cost we absorb).
        $feePesewas = intdiv($amountPesewas * DEPOSIT_FEE_BPS, 10000);
        $netToFloat = $amountPesewas - $feePesewas;

        // Three ledger entries — debits = credits:
        //   DEBIT  MOMO_FLOAT_<P>    by netToFloat
        //   DEBIT  MOMO_FEE_EXPENSE  by feePesewas
        //   CREDIT PLAYER_PLAY       by amountPesewas
        $entry = $pdo->prepare(
            "INSERT INTO ledgerEntry
                (walletTxnId, accountId, side, amountPesewas, currency, description)
             VALUES (:tx, :acc, :side, :amt, 'GHS', :desc)"
        );

        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $floatAccountId,
            ':side' => 'debit',
            ':amt'  => $netToFloat,
            ':desc' => "Net settlement from $provider MoMo (after ANM fee)",
        ]);

        if ($feePesewas > 0) {
            $entry->execute([
                ':tx'   => $walletTxnId,
                ':acc'  => $feeAccountId,
                ':side' => 'debit',
                ':amt'  => $feePesewas,
                ':desc' => 'ANM CTM fee on USSD deposit',
            ]);
        }

        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $playAccountId,
            ':side' => 'credit',
            ':amt'  => $amountPesewas,
            ':desc' => "USSD deposit via $provider",
        ]);

        // Update cached PLAY balance
        $pdo->prepare(
            "UPDATE wallet
                SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                    version = version + 1,
                    updatedAt = NOW()
              WHERE playerId = :pid AND walletType = 'PLAY'"
        )->execute([':amt' => $amountPesewas, ':pid' => $playerId]);

        // Link the walletTxn + fee back to depositRequest
        $pdo->prepare(
            "UPDATE depositRequest
                SET walletTxnId = :tx, feePesewas = :fee
              WHERE id = :id"
        )->execute([':tx' => $walletTxnId, ':fee' => $feePesewas, ':id' => $depositId]);

        ussdLog('DEP_CB_SUCCESS', [
            'depositId'   => $depositId,
            'exttrid'     => $exttrid,
            'playerId'    => $playerId,
            'amount'      => $amountPesewas,
            'fee'         => $feePesewas,
            'walletTxnId' => $walletTxnId,
        ]);

        return true;
    });
}

/**
 * Phase 3b — settle a deposit on failed ANM callback.
 */
function depositSettleFailure(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
{
    return dbTxn(function (PDO $pdo) use ($exttrid, $transStatus, $callbackIp, $rawBody): bool {
        $stmt = $pdo->prepare(
            "SELECT id, playerId, status, expiresAt
             FROM depositRequest
             WHERE refNumber = :ref
             FOR UPDATE"
        );
        $stmt->execute([':ref' => $exttrid]);
        $deposit = $stmt->fetch();

        if ($deposit === false) {
            ussdLog('DEP_CB_FAIL_UNKNOWN_REF', ['exttrid' => $exttrid]);
            return false;
        }

        if (!in_array($deposit['status'], ['initiated', 'pending'], true)) {
            ussdLog('DEP_CB_FAIL_ALREADY_PROCESSED', [
                'depositId' => $deposit['id'],
                'status'    => $deposit['status'],
            ]);
            return true;
        }

        $pdo->prepare(
            "UPDATE depositRequest
                SET status = 'failed',
                    failureCode = :code,
                    failureReason = 'MoMo could not complete the deposit. Please try again.',
                    callbackIp = :ip,
                    confirmedAt = NOW()
              WHERE id = :id AND status IN ('initiated','pending')"
        )->execute([
            ':code' => substr($transStatus, 0, 50),
            ':ip'   => $callbackIp,
            ':id'   => $deposit['id'],
        ]);

        ussdLog('DEP_CB_FAILED', [
            'depositId' => $deposit['id'],
            'exttrid'   => $exttrid,
            'status'    => $transStatus,
        ]);
        return true;
    });
}

/**
 * Look up a deposit by exttrid. Used by the callback handler to fetch info
 * for the SMS after settlement.
 */
function depositByRef(string $exttrid): ?array
{
    $stmt = db()->prepare(
        "SELECT id, refNumber, playerId, msisdn, paymentProvider, amountPesewas,
                feePesewas, status, channel, walletTxnId
         FROM depositRequest
         WHERE refNumber = :ref LIMIT 1"
    );
    $stmt->execute([':ref' => $exttrid]);
    $row = $stmt->fetch();
    if ($row === false) return null;

    return [
        'id'              => (int)$row['id'],
        'refNumber'       => (string)$row['refNumber'],
        'playerId'        => (int)$row['playerId'],
        'msisdn'          => (string)$row['msisdn'],
        'paymentProvider' => (string)$row['paymentProvider'],
        'amountPesewas'   => (int)$row['amountPesewas'],
        'feePesewas'      => (int)$row['feePesewas'],
        'status'          => (string)$row['status'],
        'channel'         => (string)$row['channel'],
        'walletTxnId'     => $row['walletTxnId'] !== null ? (int)$row['walletTxnId'] : null,
    ];
}
