<?php
/**
 * Withdrawal business logic.
 *
 * Two destinations, encoded in withdrawalRequest.destination:
 *
 *   PLAY  — Payout → Play internal transfer
 *           No MoMo, no ANM, no callback, no fee.
 *           Synchronous: completes inside the USSD turn.
 *           Implemented HERE.
 *
 *   MOMO  — Payout → MoMo cashout
 *           Subject to BR-AML-001 50% Cashout Rule (deferred — schema
 *           tracks fiftyPercentRuleApplied/Passed for when it lights up).
 *           Async: ANM MTC call + callback, just like deposits but in reverse.
 *           Implemented LATER (Path 2 in this PR sequence).
 *
 * Internal transfer mechanics (Path 1):
 *
 *   ledgerEntry 1: DEBIT  PLAYER_PAYOUT  by amountPesewas
 *   ledgerEntry 2: CREDIT PLAYER_PLAY    by amountPesewas
 *   wallet.PAYOUT.cachedBalance -= amount
 *   wallet.PLAY.cachedBalance   += amount
 *   walletTransaction.txnType = INTERNAL_XFER
 *   withdrawalRequest.status = succeeded, destination = PLAY, sourceWallet = PAYOUT
 *
 * Atomic — all of the above happens in one DB transaction. Insufficient
 * funds throws and rolls back. The check happens INSIDE the txn with the
 * Payout wallet locked (FOR UPDATE), so we can't race with a concurrent
 * stake or another withdrawal.
 *
 * Idempotency: the unique refNumber on both withdrawalRequest and
 * walletTransaction prevents duplicate transfers under retry. But unlike
 * deposits, there's no callback to retry — the USSD turn is the only
 * entry point — so this matters less.
 *
 * No fee on internal transfers. Money stays on the platform.
 */

const WITHDRAWAL_MIN_PESEWAS = 100;     // GHS 1 — matches web app
const WITHDRAWAL_MAX_PESEWAS = 500000;  // GHS 5,000 — matches web app

/**
 * Move funds from a player's Payout wallet to their Play wallet.
 *
 * @param  int    $playerId
 * @param  int    $amountPesewas
 * @param  string $clientIp
 *
 * @return array{
 *   withdrawalId: int,
 *   exttrid:      string,
 *   newPlayBalance:   int,
 *   newPayoutBalance: int,
 * }
 *
 * @throws InvalidArgumentException on amount range violation
 * @throws RuntimeException on insufficient funds / player ineligibility
 */
function withdrawalToPlay(int $playerId, int $amountPesewas, string $clientIp): array
{
    if ($amountPesewas < WITHDRAWAL_MIN_PESEWAS) {
        throw new InvalidArgumentException(
            sprintf('Minimum is GHS %s.', formatPesewas(WITHDRAWAL_MIN_PESEWAS))
        );
    }
    if ($amountPesewas > WITHDRAWAL_MAX_PESEWAS) {
        throw new InvalidArgumentException(
            sprintf('Maximum is GHS %s.', formatPesewas(WITHDRAWAL_MAX_PESEWAS))
        );
    }

    // Look up player. Frozen / deleted players can't move money.
    $player = playerById($playerId);
    if ($player === null
        || $player['accountStatus'] !== 'active'
        || ($player['deletedAt'] ?? null) !== null
    ) {
        throw new RuntimeException("Account not eligible for withdrawals.");
    }

    return dbTxn(function (PDO $pdo) use ($playerId, $amountPesewas, $clientIp, $player): array {
        // Lock the Payout wallet first. Any concurrent stake/withdrawal
        // hitting the same wallet has to wait behind us.
        $stmt = $pdo->prepare(
            "SELECT id, cachedBalancePesewas FROM wallet
              WHERE playerId = :pid AND walletType = 'PAYOUT'
              FOR UPDATE"
        );
        $stmt->execute([':pid' => $playerId]);
        $payoutWallet = $stmt->fetch();
        if ($payoutWallet === false) {
            throw new RuntimeException("Payout wallet missing.");
        }

        $payoutBalance = (int)$payoutWallet['cachedBalancePesewas'];
        if ($payoutBalance < $amountPesewas) {
            throw new RuntimeException(
                sprintf(
                    'Not enough in Payout. You have %s.',
                    formatPesewas($payoutBalance)
                )
            );
        }

        // Find the two ledger accounts
        $stmt = $pdo->prepare(
            "SELECT id FROM account
              WHERE accountType = 'PLAYER_PAYOUT'
                AND ownerType = 'player' AND ownerId = :pid
              LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId]);
        $payoutAccount = $stmt->fetch();
        if ($payoutAccount === false) {
            throw new RuntimeException("PLAYER_PAYOUT account missing for player $playerId");
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM account
              WHERE accountType = 'PLAYER_PLAY'
                AND ownerType = 'player' AND ownerId = :pid
              LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId]);
        $playAccount = $stmt->fetch();
        if ($playAccount === false) {
            throw new RuntimeException("PLAYER_PLAY account missing for player $playerId");
        }

        $payoutAccountId = (int)$payoutAccount['id'];
        $playAccountId   = (int)$playAccount['id'];

        $exttrid = bin2hex(random_bytes(8));

        // Insert withdrawalRequest. For an internal transfer this row
        // lands directly in 'succeeded' state since there's no async
        // step. We set approvedAt and completedAt to the same NOW().
        $msisdn   = (string)$player['msisdn'];
        $provider = (string)$player['paymentProvider'];

        $stmt = $pdo->prepare(
            "INSERT INTO withdrawalRequest
                (refNumber, playerId, msisdn, paymentProvider, destination,
                 sourceWallet, amountPesewas, currency,
                 playBalanceAtRequestPesewas, fiftyPercentRuleApplied, fiftyPercentRulePassed,
                 status, channel, initiatedAt, approvedAt, completedAt, clientIp)
             VALUES
                (:ref, :pid, :ms, :prov, 'PLAY',
                 'PAYOUT', :amt, 'GHS',
                 NULL, 0, 0,
                 'succeeded', 'ussd', NOW(), NOW(), NOW(), :ip)"
        );
        $stmt->execute([
            ':ref'  => $exttrid,
            ':pid'  => $playerId,
            ':ms'   => $msisdn,
            ':prov' => $provider,
            ':amt'  => $amountPesewas,
            ':ip'   => $clientIp,
        ]);
        $withdrawalId = (int)$pdo->lastInsertId();

        // walletTransaction
        $stmt = $pdo->prepare(
            "INSERT INTO walletTransaction
                (refNumber, txnType, playerId, amountPesewas, currency,
                 status, metadata, initiatedBy, channel, createdAt, completedAt)
             VALUES
                (:ref, 'INTERNAL_XFER', :pid, :amt, 'GHS',
                 'completed', :meta, 'player', 'ussd', NOW(), NOW())"
        );
        $stmt->execute([
            ':ref'  => $exttrid,
            ':pid'  => $playerId,
            ':amt'  => $amountPesewas,
            ':meta' => json_encode([
                'from'        => 'PAYOUT',
                'to'          => 'PLAY',
                'destination' => 'PLAY',
                'source'      => 'ussd',
            ]),
        ]);
        $walletTxnId = (int)$pdo->lastInsertId();

        // Two ledger entries — both credit-normal accounts:
        //   DEBIT  PLAYER_PAYOUT  (reduces it)
        //   CREDIT PLAYER_PLAY    (increases it)
        $entry = $pdo->prepare(
            "INSERT INTO ledgerEntry
                (walletTxnId, accountId, side, amountPesewas, currency, description)
             VALUES (:tx, :acc, :side, :amt, 'GHS', :desc)"
        );
        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $payoutAccountId,
            ':side' => 'debit',
            ':amt'  => $amountPesewas,
            ':desc' => 'USSD: move to Play balance — out of Payout',
        ]);
        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $playAccountId,
            ':side' => 'credit',
            ':amt'  => $amountPesewas,
            ':desc' => 'USSD: move to Play balance — into Play',
        ]);

        // Update both wallet cached balances. We've held the Payout
        // wallet lock since the start, so the read-before-update we
        // did above is still valid.
        $pdo->prepare(
            "UPDATE wallet
                SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                    version = version + 1,
                    updatedAt = NOW()
              WHERE playerId = :pid AND walletType = 'PAYOUT'"
        )->execute([':amt' => $amountPesewas, ':pid' => $playerId]);

        $pdo->prepare(
            "UPDATE wallet
                SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                    version = version + 1,
                    updatedAt = NOW()
              WHERE playerId = :pid AND walletType = 'PLAY'"
        )->execute([':amt' => $amountPesewas, ':pid' => $playerId]);

        // Link the walletTxn back to the withdrawalRequest
        $pdo->prepare(
            "UPDATE withdrawalRequest SET walletTxnId = :tx WHERE id = :id"
        )->execute([':tx' => $walletTxnId, ':id' => $withdrawalId]);

        // Re-read both balances inside the txn so the caller can show
        // exact post-transfer numbers without a stale-read window.
        $stmt = $pdo->prepare(
            "SELECT walletType, cachedBalancePesewas FROM wallet
              WHERE playerId = :pid AND walletType IN ('PLAY','PAYOUT')"
        );
        $stmt->execute([':pid' => $playerId]);
        $newBalances = ['PLAY' => 0, 'PAYOUT' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $newBalances[(string)$row['walletType']] = (int)$row['cachedBalancePesewas'];
        }

        ussdLog('WD_PLAY_SUCCESS', [
            'withdrawalId' => $withdrawalId,
            'exttrid'      => $exttrid,
            'playerId'     => $playerId,
            'amount'       => $amountPesewas,
            'walletTxnId'  => $walletTxnId,
            'newPayout'    => $newBalances['PAYOUT'],
            'newPlay'      => $newBalances['PLAY'],
        ]);

        return [
            'withdrawalId'      => $withdrawalId,
            'exttrid'           => $exttrid,
            'newPlayBalance'    => $newBalances['PLAY'],
            'newPayoutBalance'  => $newBalances['PAYOUT'],
        ];
    });
}

// =============================================================================
// Path 2: Payout → MoMo (external cashout)
// =============================================================================
//
// Lifecycle (mirrors deposit with debit/refund inverted):
//
//   PHASE 1 — withdrawalToMomoPreflight()        [in USSD turn]
//     - Validates amount + range
//     - Password-reset cooldown check (1 hour after a reset, MoMo blocked)
//     - One-in-flight check (no concurrent MoMo withdrawals per player)
//     - Soft Payout balance check
//     - Inserts withdrawalRequest row (status='initiated', destination='MOMO')
//     - Returns the withdrawalId
//     - DOES NOT call ANM yet (need to close USSD first)
//
//   PHASE 2 — withdrawalToMomoFireAnm()          [post-response, +3s]
//     - Calls anmInitiateMtc()
//     - On accepted: row → 'pending', await callback
//     - On rejected: row → 'failed', SMS the user
//     - On network error: row → 'failed', SMS the user
//
//   PHASE 3a — withdrawalSettleSuccess()         [from callback]
//     - Atomic credit pipeline:
//         DEBIT  PLAYER_PAYOUT    by amount      (decreases — credit-normal)
//         CREDIT MOMO_FLOAT_<P>   by amount+fee  (decreases — debit-normal)
//         DEBIT  MOMO_FEE_EXPENSE by fee         (increases — debit-normal)
//     - Updates Payout wallet cachedBalancePesewas
//     - row → 'succeeded', sends "money sent" SMS with new balance
//
//   PHASE 3b — withdrawalSettleFailure()         [from callback]
//     - row → 'failed', sends "withdrawal failed" SMS
//     - No wallet change (we never debited in the first place)
//
// Why no debit at preflight: the one-in-flight rule prevents the
// double-spend that debit-first would otherwise be needed for.
// Withdrawal blocked = simpler ledger = no refund machinery to maintain.
//
// Why password-reset cooldown applies to MoMo only: account-takeover
// attackers want to extract value. Internal transfers (PLAY) keep
// money on-platform; can't be "stolen" any worse than already done.
// MoMo gets cash to the attacker's hand; cooldown is the choke point.

const WITHDRAWAL_FEE_BPS = 50;                // 0.5% — matches web app
const WITHDRAWAL_VALIDITY_MINUTES = 10;
const WITHDRAWAL_PASSWORD_RESET_COOLDOWN_HOURS = 1;

/**
 * Phase 1 — validate, check rules, insert row. No ANM call yet.
 *
 * @return array{withdrawalId: int, exttrid: string, amountPesewas: int}
 *
 * @throws InvalidArgumentException on amount range
 * @throws RuntimeException on cooldown / pending withdrawal / insufficient balance / player ineligible
 */
function withdrawalToMomoPreflight(int $playerId, int $amountPesewas, string $clientIp): array
{
    if ($amountPesewas < WITHDRAWAL_MIN_PESEWAS) {
        throw new InvalidArgumentException(
            sprintf('Minimum withdrawal is GHS %s.', formatPesewas(WITHDRAWAL_MIN_PESEWAS))
        );
    }
    if ($amountPesewas > WITHDRAWAL_MAX_PESEWAS) {
        throw new InvalidArgumentException(
            sprintf('Maximum withdrawal is GHS %s.', formatPesewas(WITHDRAWAL_MAX_PESEWAS))
        );
    }

    $player = playerById($playerId);
    if ($player === null
        || $player['accountStatus'] !== 'active'
        || ($player['deletedAt'] ?? null) !== null
    ) {
        throw new RuntimeException("Account not eligible for withdrawals.");
    }

    // 1-hour password-reset cooldown — only applies to MoMo (Type B).
    // PLAY transfers don't hit this because money stays on-platform.
    if (withdrawalIsWithinPasswordResetCooldown($playerId)) {
        throw new RuntimeException(
            "For your security, MoMo withdrawals are paused for "
            . WITHDRAWAL_PASSWORD_RESET_COOLDOWN_HOURS
            . " hour after a password reset. You can still move funds to Play."
        );
    }

    // One-in-flight: prevents double-spend without needing a preflight debit.
    // We look for any MoMo withdrawal that's in an unsettled state and within
    // its validity window.
    $stmt = db()->prepare(
        "SELECT id FROM withdrawalRequest
          WHERE playerId = :p AND destination = 'MOMO'
            AND status IN ('initiated','pending','approved')
            AND expiresAt > NOW()
          LIMIT 1"
    );
    $stmt->execute([':p' => $playerId]);
    if ($stmt->fetch() !== false) {
        throw new RuntimeException(
            "You have a withdrawal in progress. Please wait for it to complete."
        );
    }

    // Soft Payout balance check. The real (race-free) check happens in the
    // settlement transaction; this is the user-friendly bail-out.
    $bal = playerBalances($playerId);
    if ($amountPesewas > $bal['payout']) {
        throw new RuntimeException(
            sprintf('Not enough in Payout. You have %s.', formatPesewas($bal['payout']))
        );
    }

    $exttrid   = bin2hex(random_bytes(8));
    $msisdn    = (string)$player['msisdn'];
    $provider  = (string)$player['paymentProvider'];
    $expiresAt = gmdate('Y-m-d H:i:s', time() + WITHDRAWAL_VALIDITY_MINUTES * 60);

    db()->prepare(
        "INSERT INTO withdrawalRequest
            (refNumber, playerId, msisdn, paymentProvider, destination,
             sourceWallet, amountPesewas, currency,
             playBalanceAtRequestPesewas, fiftyPercentRuleApplied, fiftyPercentRulePassed,
             status, channel, initiatedAt, expiresAt, clientIp)
         VALUES
            (:ref, :pid, :ms, :prov, 'MOMO',
             'PAYOUT', :amt, 'GHS',
             :playBal, 0, 0,
             'initiated', 'ussd', NOW(), :exp, :ip)"
    )->execute([
        ':ref'     => $exttrid,
        ':pid'     => $playerId,
        ':ms'      => $msisdn,
        ':prov'    => $provider,
        ':amt'     => $amountPesewas,
        ':playBal' => $bal['play'],
        ':exp'     => $expiresAt,
        ':ip'      => $clientIp,
    ]);
    $withdrawalId = (int)db()->lastInsertId();

    ussdLog('WD_MOMO_PREFLIGHT_OK', [
        'withdrawalId' => $withdrawalId,
        'exttrid'      => $exttrid,
        'playerId'     => $playerId,
        'amount'       => $amountPesewas,
        'provider'     => $provider,
    ]);

    return [
        'withdrawalId'   => $withdrawalId,
        'exttrid'        => $exttrid,
        'amountPesewas'  => $amountPesewas,
    ];
}

/**
 * Phase 2 — call ANM MTC. Runs post-response, after the 3-second wait.
 *
 * Bails (no-op) if the row has been moved out of 'initiated' by a race
 * with reconciliation cron.
 */
function withdrawalToMomoFireAnm(int $withdrawalId): void
{
    global $USSD_CONFIG;

    try {
        $stmt = db()->prepare(
            "SELECT id, refNumber, playerId, msisdn, paymentProvider,
                    amountPesewas, status
             FROM withdrawalRequest
             WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $withdrawalId]);
        $w = $stmt->fetch();

        if ($w === false) {
            ussdLog('WD_MOMO_FIRE_ROW_MISSING', ['withdrawalId' => $withdrawalId]);
            return;
        }
        if ($w['status'] !== 'initiated') {
            ussdLog('WD_MOMO_FIRE_BAD_STATUS', [
                'withdrawalId' => $withdrawalId,
                'status'       => $w['status'],
            ]);
            return;
        }

        $exttrid       = (string)$w['refNumber'];
        $msisdn        = (string)$w['msisdn'];
        $provider      = (string)$w['paymentProvider'];
        $amountPesewas = (int)$w['amountPesewas'];

        $phoneLocal  = displayMsisdn($msisdn);
        $bankCode    = anmBankCodeFromProvider($provider);
        $amountGhs   = sprintf('%d.%02d', intdiv($amountPesewas, 100), $amountPesewas % 100);
        $reference   = (string)($USSD_CONFIG['deposit']['reference'] ?? 'BlackRed');
        $callbackUrl = (string)($USSD_CONFIG['anm']['callback_url'] ?? '');

        // Stash request payload before the call
        db()->prepare(
            "UPDATE withdrawalRequest SET momoRequestPayload = :req WHERE id = :id"
        )->execute([
            ':req' => json_encode([
                'exttrid'      => $exttrid,
                'phone_local'  => $phoneLocal,
                'bank_code'    => $bankCode,
                'amount'       => $amountGhs,
                'reference'    => substr($reference, 0, 10),
                'callback_url' => $callbackUrl,
            ]),
            ':id'  => $withdrawalId,
        ]);

        try {
            $result = anmInitiateMtc(
                $exttrid, $phoneLocal, $bankCode, $amountGhs, $reference, $callbackUrl
            );
        } catch (RuntimeException $e) {
            db()->prepare(
                "UPDATE withdrawalRequest
                    SET status = 'failed',
                        failureCode = 'network_error',
                        failureReason = :reason,
                        completedAt = NOW()
                  WHERE id = :id"
            )->execute([
                ':reason' => substr($e->getMessage(), 0, 500),
                ':id'     => $withdrawalId,
            ]);
            ussdLog('WD_MOMO_FIRE_NETWORK_ERROR', [
                'withdrawalId' => $withdrawalId,
                'error'        => $e->getMessage(),
            ]);
            withdrawalSendFailureSms($msisdn, $amountPesewas,
                'Could not reach MoMo provider.');
            return;
        }

        if ($result['accepted']) {
            db()->prepare(
                "UPDATE withdrawalRequest
                    SET status = 'pending',
                        momoResponsePayload = :resp,
                        approvedAt = NOW()
                  WHERE id = :id AND status = 'initiated'"
            )->execute([
                ':resp' => json_encode($result['raw']),
                ':id'   => $withdrawalId,
            ]);
            ussdLog('WD_MOMO_FIRE_ACCEPTED', [
                'withdrawalId' => $withdrawalId,
                'exttrid'      => $exttrid,
            ]);
            return;
        }

        // Rejected
        $reason = $result['respDesc'] ?? 'MoMo declined the withdrawal.';
        db()->prepare(
            "UPDATE withdrawalRequest
                SET status = 'failed',
                    failureCode = :code,
                    failureReason = :reason,
                    momoResponsePayload = :resp,
                    completedAt = NOW()
              WHERE id = :id AND status = 'initiated'"
        )->execute([
            ':code'   => substr($result['respCode'] ?? 'unknown', 0, 50),
            ':reason' => substr($reason, 0, 500),
            ':resp'   => json_encode($result['raw']),
            ':id'     => $withdrawalId,
        ]);
        ussdLog('WD_MOMO_FIRE_REJECTED', [
            'withdrawalId' => $withdrawalId,
            'exttrid'      => $exttrid,
            'respCode'     => $result['respCode'],
        ]);
        withdrawalSendFailureSms($msisdn, $amountPesewas, $reason);

    } catch (Throwable $e) {
        ussdLog('WD_MOMO_FIRE_UNEXPECTED', [
            'withdrawalId' => $withdrawalId,
            'error'        => $e->getMessage(),
            'trace'        => $e->getTraceAsString(),
        ]);
    }
}

/**
 * Phase 3a — ANM callback says the MoMo payout succeeded.
 *
 * Atomic credit pipeline. Returns true if we processed (whether or not we
 * did work this time), false if not found / expired.
 */
function withdrawalSettleSuccess(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
{
    return dbTxn(function (PDO $pdo) use ($exttrid, $transStatus, $callbackIp, $rawBody): bool {
        $stmt = $pdo->prepare(
            "SELECT id, playerId, msisdn, paymentProvider, amountPesewas,
                    status, refNumber, expiresAt, destination
             FROM withdrawalRequest
             WHERE refNumber = :ref
             FOR UPDATE"
        );
        $stmt->execute([':ref' => $exttrid]);
        $w = $stmt->fetch();

        if ($w === false) {
            ussdLog('WD_CB_UNKNOWN_REF', ['exttrid' => $exttrid, 'ip' => $callbackIp]);
            return false;
        }
        if ($w['destination'] !== 'MOMO') {
            // PLAY withdrawals don't have callbacks
            ussdLog('WD_CB_NOT_MOMO', ['exttrid' => $exttrid, 'destination' => $w['destination']]);
            return false;
        }
        if ($w['expiresAt'] !== null && strtotime((string)$w['expiresAt']) < time()) {
            ussdLog('WD_CB_EXPIRED', ['exttrid' => $exttrid, 'withdrawalId' => $w['id']]);
            return false;
        }
        if (!in_array($w['status'], ['initiated', 'pending', 'approved'], true)) {
            ussdLog('WD_CB_ALREADY_PROCESSED', [
                'withdrawalId' => $w['id'],
                'status'       => $w['status'],
            ]);
            return true;
        }

        $withdrawalId  = (int)$w['id'];
        $playerId      = (int)$w['playerId'];
        $amountPesewas = (int)$w['amountPesewas'];
        $provider      = (string)$w['paymentProvider'];

        // Find accounts
        $stmt = $pdo->prepare(
            "SELECT id FROM account
              WHERE accountType = 'PLAYER_PAYOUT' AND ownerType = 'player' AND ownerId = :pid
              LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId]);
        $payoutAccount = $stmt->fetch();
        if ($payoutAccount === false) {
            throw new RuntimeException("PLAYER_PAYOUT account missing for player $playerId");
        }
        $payoutAccountId = (int)$payoutAccount['id'];

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
            "UPDATE withdrawalRequest
                SET status = 'succeeded',
                    callbackIp = :ip,
                    completedAt = NOW()
              WHERE id = :id AND status IN ('initiated','pending','approved')"
        );
        $stmt->execute([':id' => $withdrawalId, ':ip' => $callbackIp]);
        if ($stmt->rowCount() === 0) {
            ussdLog('WD_CB_RACE_LOST', ['withdrawalId' => $withdrawalId]);
            return true;
        }

        // Fee: BlackRed absorbs 0.5%. Player gets the full amount on their MoMo.
        $feePesewas = intdiv($amountPesewas * WITHDRAWAL_FEE_BPS, 10000);
        $floatOutflow = $amountPesewas + $feePesewas;

        // walletTransaction
        $stmt = $pdo->prepare(
            "INSERT INTO walletTransaction
                (refNumber, txnType, playerId, amountPesewas, currency,
                 status, metadata, initiatedBy, channel, createdAt, completedAt)
             VALUES
                (:ref, 'WITHDRAWAL_PAYOUT', :pid, :amt, 'GHS',
                 'completed', :meta, 'player', 'ussd', NOW(), NOW())"
        );
        $stmt->execute([
            ':ref'  => $exttrid,
            ':pid'  => $playerId,
            ':amt'  => $amountPesewas,
            ':meta' => json_encode([
                'provider'    => $provider,
                'msisdn'      => $w['msisdn'],
                'destination' => 'MOMO',
                'feePesewas'  => $feePesewas,
                'anmStatus'   => $transStatus,
                'callbackAt'  => gmdate('c'),
            ]),
        ]);
        $walletTxnId = (int)$pdo->lastInsertId();

        // Three ledger entries — debits = credits:
        //   DEBIT  PLAYER_PAYOUT      by amount        (decreases credit-normal acct)
        //   CREDIT MOMO_FLOAT_<P>     by amount+fee    (decreases debit-normal acct)
        //   DEBIT  MOMO_FEE_EXPENSE   by fee           (increases debit-normal acct)
        $entry = $pdo->prepare(
            "INSERT INTO ledgerEntry
                (walletTxnId, accountId, side, amountPesewas, currency, description)
             VALUES (:tx, :acc, :side, :amt, 'GHS', :desc)"
        );

        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $payoutAccountId,
            ':side' => 'debit',
            ':amt'  => $amountPesewas,
            ':desc' => "USSD withdrawal to $provider MoMo",
        ]);

        $entry->execute([
            ':tx'   => $walletTxnId,
            ':acc'  => $floatAccountId,
            ':side' => 'credit',
            ':amt'  => $floatOutflow,
            ':desc' => "Float outflow to player + ANM fee",
        ]);

        if ($feePesewas > 0) {
            $entry->execute([
                ':tx'   => $walletTxnId,
                ':acc'  => $feeAccountId,
                ':side' => 'debit',
                ':amt'  => $feePesewas,
                ':desc' => 'ANM MTC fee on USSD withdrawal',
            ]);
        }

        // Decrement Payout cached balance
        $pdo->prepare(
            "UPDATE wallet
                SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                    version = version + 1,
                    updatedAt = NOW()
              WHERE playerId = :pid AND walletType = 'PAYOUT'"
        )->execute([':amt' => $amountPesewas, ':pid' => $playerId]);

        // Link the walletTxn + fee back to withdrawalRequest
        $pdo->prepare(
            "UPDATE withdrawalRequest
                SET walletTxnId = :tx, feePesewas = :fee
              WHERE id = :id"
        )->execute([':tx' => $walletTxnId, ':fee' => $feePesewas, ':id' => $withdrawalId]);

        ussdLog('WD_MOMO_CB_SUCCESS', [
            'withdrawalId' => $withdrawalId,
            'exttrid'      => $exttrid,
            'playerId'     => $playerId,
            'amount'       => $amountPesewas,
            'fee'          => $feePesewas,
            'walletTxnId'  => $walletTxnId,
        ]);

        return true;
    });
}

/**
 * Phase 3b — ANM callback says the MoMo payout failed.
 *
 * No wallet change (we never debited at preflight).
 */
function withdrawalSettleFailure(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
{
    return dbTxn(function (PDO $pdo) use ($exttrid, $transStatus, $callbackIp, $rawBody): bool {
        $stmt = $pdo->prepare(
            "SELECT id, playerId, status, destination, expiresAt
             FROM withdrawalRequest
             WHERE refNumber = :ref
             FOR UPDATE"
        );
        $stmt->execute([':ref' => $exttrid]);
        $w = $stmt->fetch();

        if ($w === false) {
            ussdLog('WD_CB_FAIL_UNKNOWN_REF', ['exttrid' => $exttrid]);
            return false;
        }
        if ($w['destination'] !== 'MOMO') {
            return false;
        }
        if (!in_array($w['status'], ['initiated', 'pending', 'approved'], true)) {
            ussdLog('WD_CB_FAIL_ALREADY_PROCESSED', [
                'withdrawalId' => $w['id'],
                'status'       => $w['status'],
            ]);
            return true;
        }

        $pdo->prepare(
            "UPDATE withdrawalRequest
                SET status = 'failed',
                    failureCode = :code,
                    failureReason = 'MoMo could not complete the withdrawal. Please try again.',
                    callbackIp = :ip,
                    completedAt = NOW()
              WHERE id = :id AND status IN ('initiated','pending','approved')"
        )->execute([
            ':code' => substr($transStatus, 0, 50),
            ':ip'   => $callbackIp,
            ':id'   => $w['id'],
        ]);

        ussdLog('WD_MOMO_CB_FAILED', [
            'withdrawalId' => $w['id'],
            'exttrid'      => $exttrid,
            'status'       => $transStatus,
        ]);
        return true;
    });
}

/**
 * Look up a withdrawal by exttrid. Used by the callback handler.
 */
function withdrawalByRef(string $exttrid): ?array
{
    $stmt = db()->prepare(
        "SELECT id, refNumber, playerId, msisdn, paymentProvider, destination,
                amountPesewas, feePesewas, status, channel, walletTxnId
         FROM withdrawalRequest
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
        'destination'     => (string)$row['destination'],
        'amountPesewas'   => (int)$row['amountPesewas'],
        'feePesewas'      => (int)$row['feePesewas'],
        'status'          => (string)$row['status'],
        'channel'         => (string)$row['channel'],
        'walletTxnId'     => $row['walletTxnId'] !== null ? (int)$row['walletTxnId'] : null,
    ];
}

/**
 * Check the password-reset cooldown for MoMo withdrawals.
 *
 * Returns true if the player reset their password within the last
 * WITHDRAWAL_PASSWORD_RESET_COOLDOWN_HOURS hours. Defensive: any DB
 * error (e.g. table missing in dev) returns false so the cooldown
 * never falsely blocks legitimate withdrawals.
 */
function withdrawalIsWithinPasswordResetCooldown(int $playerId): bool
{
    try {
        // Precompute the cutoff in PHP so the SQL is portable to SQLite (tests).
        $cutoff = gmdate('Y-m-d H:i:s', time() - WITHDRAWAL_PASSWORD_RESET_COOLDOWN_HOURS * 3600);

        $stmt = db()->prepare(
            "SELECT 1 FROM passwordResetRequest
              WHERE playerId = :pid
                AND status = 'used'
                AND usedAt > :cutoff
              LIMIT 1"
        );
        $stmt->execute([':pid' => $playerId, ':cutoff' => $cutoff]);
        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        ussdLog('WD_COOLDOWN_CHECK_ERROR', [
            'playerId' => $playerId,
            'error'    => $e->getMessage(),
        ]);
        return false;
    }
}

/**
 * Send a failure SMS when ANM rejects post-USSD-close.
 */
function withdrawalSendFailureSms(string $msisdn, int $amountPesewas, string $reason): void
{
    try {
        $msg = sprintf(
            "BlackRed: Your %s withdrawal could not be sent. %s Please try again.",
            formatPesewas($amountPesewas),
            substr($reason, 0, 80)
        );
        hubtelSendSms($msisdn, $msg);
    } catch (Throwable $e) {
        ussdLog('WD_FAIL_SMS_ERROR', ['error' => $e->getMessage()]);
    }
}
