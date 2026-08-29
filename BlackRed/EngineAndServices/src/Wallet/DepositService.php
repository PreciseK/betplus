<?php

declare(strict_types=1);

namespace BlackRed\Wallet;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Integrations\AnmClient;
use BlackRed\Logging\Logger;
use BlackRed\Support\Money;

/**
 * DepositService — Phase 3 wallet top-up via ANM CTM MoMo.
 *
 * Two responsibilities:
 *
 *   1. initiate(): caller asks to deposit GHS X. We create a depositRequest
 *      row in 'initiated' state, then call ANM. If ANM accepts the request
 *      (the customer's phone will get a PIN prompt), we move to 'pending'
 *      and return the row to the caller. Caller polls until status changes.
 *
 *   2. handleCallback(): ANM POSTs to our public callback endpoint when
 *      money has actually moved (or failed). We verify, look up the deposit,
 *      and atomically post double-entry ledger entries to credit the
 *      player's PLAY wallet from the appropriate MOMO_FLOAT account.
 *
 * Security model (until ANM IP allowlist is in place):
 *
 *   - exttrid is 16 hex chars from random_bytes(8). Unguessable for our
 *     purposes (64 bits of entropy) and fits within ANM's 20-char limit.
 *   - Callbacks past expiresAt (initiatedAt + 10 min) are rejected even
 *     if status is success. Bounds the replay window.
 *   - All callbacks are idempotent: an already-completed deposit is a no-op.
 *     We never credit the same exttrid twice.
 *   - Amount is read from our DB (set at initiate time), NEVER from any
 *     callback field. ANM never tells us how much to credit.
 *
 * Audit trail:
 *   - momoApiCallLog gets a row for the CTM initiate
 *   - depositRequest carries momoRequestPayload + momoResponsePayload
 *   - walletTransaction (ref = exttrid) created on success
 *   - ledgerEntry rows debit MOMO_FLOAT_<network>, credit PLAYER_PLAY:<id>
 */
final class DepositService
{
    private const MIN_GHS_PESEWAS = 200;       // GHS 2.00
    private const MAX_GHS_PESEWAS = 500000;    // GHS 5,000.00
    private const VALIDITY_MINUTES = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly AnmClient $anm,
        private readonly Logger $logger,
        private readonly string $callbackUrl,
        private readonly string $merchantReference,
        // Fee charged by ANM, in basis points (1 bps = 0.01%). E.g. 100 = 1%.
        // Per migration 005, we deduct this from the MOMO_FLOAT_MTN debit and
        // post the fee as a separate DEBIT to MOMO_FEE_EXPENSE.
        private readonly int $depositFeeBps = 100,
    ) {
    }

    /**
     * Step 1 — initiate a deposit.
     *
     * Returns the depositRequest row in either 'pending' (ANM accepted —
     * customer's phone is about to ring) or 'failed' (ANM rejected —
     * something wrong with phone or amount).
     *
     * @return array depositRequest row including refNumber and status
     * @throws HttpException 422 if amount out of range
     */
    public function initiate(int $playerId, int $amountPesewas, string $clientIp): array
    {
        if ($amountPesewas < self::MIN_GHS_PESEWAS) {
            throw new HttpException(422, 'amount_too_low', sprintf(
                'Minimum deposit is GHS %s.',
                Money::pesewasToString(self::MIN_GHS_PESEWAS)
            ));
        }
        if ($amountPesewas > self::MAX_GHS_PESEWAS) {
            throw new HttpException(422, 'amount_too_high', sprintf(
                'Maximum deposit per transaction is GHS %s.',
                Money::pesewasToString(self::MAX_GHS_PESEWAS)
            ));
        }

        $player = $this->db->fetchOne(
            'SELECT id, msisdn, paymentProvider, accountStatus, deletedAt
             FROM player WHERE id = :id LIMIT 1',
            ['id' => $playerId]
        );
        if ($player === null
            || $player['deletedAt'] !== null
            || $player['accountStatus'] !== 'active'
        ) {
            throw new HttpException(403, 'account_not_eligible', 'Account is not eligible for deposits.');
        }

        // Reject if there's already a pending deposit for this player. Stops
        // accidental double-taps and gives the user time to actually
        // approve the existing prompt before starting another.
        $existing = $this->db->fetchOne(
            'SELECT refNumber, expiresAt FROM depositRequest
              WHERE playerId = :p
                AND status IN ("initiated","pending")
                AND expiresAt > NOW()
              LIMIT 1',
            ['p' => $playerId]
        );
        if ($existing !== null) {
            throw new HttpException(409, 'pending_deposit_exists',
                'You already have a deposit waiting for approval. Approve or wait for it to expire.');
        }

        // 16 hex chars = 8 bytes of cryptographic entropy (64 bits =
        // unguessable for our purposes). Within ANM's 20-char exttrid limit.
        $exttrid = bin2hex(random_bytes(8));

        // Convert pesewas to ANM's expected GHS-decimal-string format.
        // Money::pesewasToString gives "50.00" — exactly what they want.
        $amountGhs = Money::pesewasToString($amountPesewas);

        $msisdn = (string)$player['msisdn'];
        $provider = (string)$player['paymentProvider'];
        $phoneLocal = '0' . substr($msisdn, 3); // 233244000001 -> 0244000001

        // Insert depositRequest BEFORE calling ANM. This guarantees we have a
        // database record even if ANM hangs or our process is killed mid-call.
        // Status starts 'initiated'; we move to 'pending' if ANM accepts.
        $this->db->execute(
            'INSERT INTO depositRequest
                (refNumber, playerId, msisdn, paymentProvider, amountPesewas,
                 status, channel, initiatedAt, expiresAt, clientIp)
             VALUES
                (:ref, :pid, :msisdn, :prov, :amt,
                 :status, :ch, NOW(), DATE_ADD(NOW(), INTERVAL :mins MINUTE), :ip)',
            [
                'ref'    => $exttrid,
                'pid'    => $playerId,
                'msisdn' => $msisdn,
                'prov'   => $provider,
                'amt'    => $amountPesewas,
                'status' => 'initiated',
                'ch'     => 'web',
                'mins'   => self::VALIDITY_MINUTES,
                'ip'     => $clientIp,
            ]
        );
        $depositId = (int)$this->db->pdo()->lastInsertId();

        $this->logger->info('deposit_initiate_start', [
            'deposit_id' => $depositId,
            'player_id'  => $playerId,
            'exttrid'    => $exttrid,
            'amount'     => $amountGhs,
            'provider'   => $provider,
        ]);

        // Call ANM. This may take a few seconds.
        $anmResult = $this->anm->initiateCtm(
            $exttrid,
            $phoneLocal,
            $provider,
            $amountGhs,
            // ANM caps reference at 10 chars per docs. Truncate defensively.
            // The full configured value is still useful for our internal logs.
            substr($this->merchantReference, 0, 10),
            $this->callbackUrl,
        );

        // Update with ANM's response (request payload, response payload, status)
        if ($anmResult['accepted']) {
            $this->db->execute(
                'UPDATE depositRequest
                    SET status = "pending",
                        momoResponsePayload = :resp
                  WHERE id = :id',
                [
                    'id'   => $depositId,
                    'resp' => json_encode($anmResult['raw']),
                ]
            );
            $this->logger->info('deposit_initiate_accepted', [
                'deposit_id' => $depositId,
                'exttrid'    => $exttrid,
            ]);
        } else {
            $this->db->execute(
                'UPDATE depositRequest
                    SET status = "failed",
                        failureCode = :code,
                        failureReason = :reason,
                        momoResponsePayload = :resp,
                        confirmedAt = NOW()
                  WHERE id = :id',
                [
                    'id'     => $depositId,
                    'code'   => $anmResult['statusCode'] ?? 'unknown',
                    // failureReason is shown to the player. Keep it
                    // human and never mention internal partner names.
                    'reason' => substr($anmResult['message'] ?? 'Mobile Money declined the request. Please try again.', 0, 500),
                    'resp'   => json_encode($anmResult['raw']),
                ]
            );
            $this->logger->info('deposit_initiate_rejected', [
                'deposit_id' => $depositId,
                'exttrid'    => $exttrid,
                'code'       => $anmResult['statusCode'],
            ]);
        }

        return $this->getById($depositId, $playerId);
    }

    /**
     * Step 2 — handle ANM callback.
     *
     * Called from the public callback endpoint. Returns true if we
     * processed the callback (regardless of outcome). False if rejected
     * outright (e.g. expired, unknown exttrid). The endpoint always
     * returns 200 to ANM either way so they don't retry forever.
     *
     * The CRITICAL invariant: this method is idempotent. Calling it twice
     * for the same exttrid with the same status is a no-op on the second
     * call. The first call wins; the second call sees status != 'pending'
     * and returns without changing anything.
     */
    public function handleCallback(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
    {
        // Validate exttrid shape — defends against trivial probes
        // Accept either the new 16-char format (current) or legacy 32-char
        // format (for any deposits initiated before the schema change).
        // Anything else is malformed and we reject without a DB lookup.
        if (!preg_match('/^[a-f0-9]{16}$|^[a-f0-9]{32}$/', $exttrid)) {
            $this->logger->info('deposit_callback_bad_exttrid', [
                'exttrid_len' => strlen($exttrid),
                'callback_ip' => $callbackIp,
            ]);
            return false;
        }

        // Look up the deposit by refNumber (which equals our exttrid)
        $deposit = $this->db->fetchOne(
            'SELECT id, playerId, msisdn, paymentProvider, amountPesewas,
                    status, refNumber, expiresAt
             FROM depositRequest
             WHERE refNumber = :ref LIMIT 1',
            ['ref' => $exttrid]
        );

        if ($deposit === null) {
            $this->logger->info('deposit_callback_unknown_exttrid', [
                'exttrid'     => $exttrid,
                'callback_ip' => $callbackIp,
            ]);
            return false;
        }

        // Replay window check
        if ($deposit['expiresAt'] !== null && strtotime((string)$deposit['expiresAt']) < time()) {
            $this->logger->info('deposit_callback_expired', [
                'deposit_id'  => $deposit['id'],
                'exttrid'     => $exttrid,
                'callback_ip' => $callbackIp,
            ]);
            return false;
        }

        // Already-processed check — idempotency guarantee
        if (!in_array($deposit['status'], ['initiated', 'pending'], true)) {
            $this->logger->info('deposit_callback_already_processed', [
                'deposit_id' => $deposit['id'],
                'exttrid'    => $exttrid,
                'status'     => $deposit['status'],
            ]);
            return true; // Successfully handled — no work to do
        }

        // Parse status code per ANM convention "000/200" — first segment is
        // the auth/business outcome. "000" = success, anything else = failure.
        $parts = explode('/', $transStatus);
        $primary = $parts[0] ?? '';
        $isSuccess = $primary === '000';

        if ($isSuccess) {
            return $this->postSuccess((int)$deposit['id'], $deposit, $transStatus, $callbackIp, $rawBody);
        }

        // Failure path — mark as failed, no money movement.
        $this->db->execute(
            'UPDATE depositRequest
                SET status = "failed",
                    failureCode = :code,
                    failureReason = "Mobile Money could not complete the deposit. Please try again or check your account balance.",
                    callbackIp = :ip,
                    confirmedAt = NOW()
              WHERE id = :id AND status IN ("initiated","pending")',
            [
                'id'   => $deposit['id'],
                'code' => substr($transStatus, 0, 50),
                'ip'   => $callbackIp,
            ]
        );
        $this->logger->info('deposit_callback_failed', [
            'deposit_id' => $deposit['id'],
            'exttrid'    => $exttrid,
            'status'     => $transStatus,
        ]);
        return true;
    }

    /**
     * The success path — runs inside a transaction, posts double-entry,
     * updates wallet cache, marks deposit succeeded. Idempotent via
     * conditional UPDATE on status.
     */
    private function postSuccess(int $depositId, array $deposit, string $transStatus, string $callbackIp, ?array $rawBody): bool
    {
        return $this->db->transactional(function (Connection $db) use ($depositId, $deposit, $transStatus, $callbackIp, $rawBody): bool {
            // Conditional UPDATE: only succeeds if still pending. If a
            // concurrent callback (ANM retry) already won, this UPDATE
            // affects 0 rows and we bail.
            $db->execute(
                'UPDATE depositRequest
                    SET status = "succeeded",
                        callbackIp = :ip,
                        confirmedAt = NOW()
                  WHERE id = :id AND status IN ("initiated","pending")',
                ['id' => $depositId, 'ip' => $callbackIp]
            );

            // Re-check that we actually changed it. Without this we'd
            // double-credit on retry.
            $row = $db->fetchOne(
                'SELECT status FROM depositRequest WHERE id = :id',
                ['id' => $depositId]
            );
            if ($row === null || $row['status'] !== 'succeeded') {
                $this->logger->info('deposit_callback_race_lost', [
                    'deposit_id' => $depositId,
                    'now_status' => $row['status'] ?? 'missing',
                ]);
                return true;
            }

            $playerId      = (int)$deposit['playerId'];
            $amountPesewas = (int)$deposit['amountPesewas'];
            $provider      = (string)$deposit['paymentProvider'];
            $exttrid       = (string)$deposit['refNumber'];

            // Find the player's PLAY account
            $playAccount = $db->fetchOne(
                'SELECT id FROM account
                  WHERE accountType = "PLAYER_PLAY"
                    AND ownerType = "player" AND ownerId = :pid
                  LIMIT 1',
                ['pid' => $playerId]
            );
            if ($playAccount === null) {
                throw new \RuntimeException("PLAYER_PLAY account missing for player $playerId");
            }
            $playAccountId = (int)$playAccount['id'];

            // Find the MOMO_FLOAT account for this network
            $floatType = 'MOMO_FLOAT_' . $provider; // MTN, ATL, TEL
            $floatAccount = $db->fetchOne(
                'SELECT id FROM account
                  WHERE accountType = :t AND ownerType = "momo"
                  LIMIT 1',
                ['t' => $floatType]
            );
            if ($floatAccount === null) {
                throw new \RuntimeException("$floatType account not seeded");
            }
            $floatAccountId = (int)$floatAccount['id'];

            // Create walletTransaction (ref = exttrid; UNIQUE, double-create
            // would throw)
            $walletTxnId = $db->insert(
                'INSERT INTO walletTransaction
                    (refNumber, txnType, playerId, amountPesewas, currency,
                     status, metadata, initiatedBy, channel, createdAt, completedAt)
                 VALUES
                    (:ref, "DEPOSIT", :pid, :amt, "GHS",
                     "completed", :meta, "player", "web", NOW(), NOW())',
                [
                    'ref'  => $exttrid,
                    'pid'  => $playerId,
                    'amt'  => $amountPesewas,
                    'meta' => json_encode([
                        'provider'   => $provider,
                        'msisdn'     => $deposit['msisdn'],
                        'anmStatus'  => $transStatus,
                        'callbackAt' => gmdate('c'),
                    ]),
                ]
            );

            // Compute fee. ANM deducts this from our settlement, so the
            // amount that actually arrives in our float is (amount - fee).
            // Player still gets credited the full amount (we absorb the fee).
            // Basis points avoids float math: feePesewas = amt * bps / 10000.
            $feePesewas = intdiv($amountPesewas * $this->depositFeeBps, 10000);
            $netToFloat = $amountPesewas - $feePesewas;

            // Find the MOMO_FEE_EXPENSE account (debit-normal, system-owned)
            $feeAccount = $db->fetchOne(
                'SELECT id FROM account WHERE accountCode = "MOMO_FEE_EXPENSE" LIMIT 1'
            );
            if ($feeAccount === null) {
                throw new \RuntimeException('MOMO_FEE_EXPENSE account not seeded — run migration 005');
            }
            $feeAccountId = (int)$feeAccount['id'];

            // Three ledger entries (matched debits = credits):
            //   DEBIT  MOMO_FLOAT_MTN  by netToFloat   (what actually settled to us)
            //   DEBIT  MOMO_FEE_EXPENSE by feePesewas  (what ANM kept)
            //   CREDIT PLAYER_PLAY     by amountPesewas (full amount the player gets)
            //
            // Sum of debits = netToFloat + feePesewas = amountPesewas = sum of credits ✓
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "debit", :amt, "GHS", :desc)',
                [
                    'tx'   => $walletTxnId,
                    'acc'  => $floatAccountId,
                    'amt'  => $netToFloat,
                    'desc' => "Net settlement from $provider MoMo (after ANM fee)",
                ]
            );
            if ($feePesewas > 0) {
                $db->execute(
                    'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                     VALUES (:tx, :acc, "debit", :amt, "GHS", :desc)',
                    [
                        'tx'   => $walletTxnId,
                        'acc'  => $feeAccountId,
                        'amt'  => $feePesewas,
                        'desc' => "ANM CTM fee on deposit",
                    ]
                );
            }
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "credit", :amt, "GHS", :desc)',
                [
                    'tx'   => $walletTxnId,
                    'acc'  => $playAccountId,
                    'amt'  => $amountPesewas,
                    'desc' => "Wallet top-up via $provider",
                ]
            );

            // Update wallet cached balance (canonical truth is the ledger;
            // wallet.cachedBalancePesewas is just a fast-read hint)
            $db->execute(
                'UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                        version = version + 1,
                        updatedAt = NOW()
                  WHERE playerId = :pid AND walletType = "PLAY"',
                ['amt' => $amountPesewas, 'pid' => $playerId]
            );

            // Link the walletTxn and stamp the fee back to the depositRequest
            $db->execute(
                'UPDATE depositRequest
                    SET walletTxnId = :tx, feePesewas = :fee
                  WHERE id = :id',
                ['tx' => $walletTxnId, 'fee' => $feePesewas, 'id' => $depositId]
            );

            $this->logger->info('deposit_callback_success', [
                'deposit_id'    => $depositId,
                'exttrid'       => $exttrid,
                'player_id'     => $playerId,
                'amount_pesewas'=> $amountPesewas,
                'fee_pesewas'   => $feePesewas,
                'wallet_txn_id' => $walletTxnId,
            ]);

            return true;
        });
    }

    /**
     * User-driven status check (called when player taps "I've made payment").
     *
     * Strategy:
     *   1. Read our local depositRequest row by id (player-scoped).
     *   2. If status is anything other than 'initiated'/'pending', just
     *      return the row — we already know the outcome from the callback.
     *   3. If still pending, call ANM's TSC endpoint (Transaction Status
     *      Check) to find out if they consider it succeeded/failed.
     *   4. If TSC reports success, run the same atomic credit pipeline
     *      that the callback would have run, and return the now-succeeded
     *      row.
     *   5. If TSC reports failure, mark our row failed and return.
     *   6. If TSC is unavailable or still pending on their side, return
     *      the unchanged pending row — caller will ask user to wait+retry.
     *
     * This is our defense against lost callbacks. If ANM's webhook never
     * reaches us (network glitch, our app down briefly, IP allowlist
     * misconfigured), the user's next "I've made payment" tap will
     * actively reconcile via TSC and credit the wallet.
     *
     * Idempotent — calling this on an already-succeeded deposit is a no-op.
     */
    public function verifyWithAnm(int $depositId, int $playerId): ?array
    {
        $deposit = $this->getById($depositId, $playerId);
        if ($deposit === null) {
            return null;
        }

        if (!in_array($deposit['status'], ['initiated', 'pending'], true)) {
            return $deposit;
        }

        $exttrid = (string)$deposit['refNumber'];
        $tsc = $this->anm->checkTransaction($exttrid);

        if (!$tsc['found']) {
            $this->logger->info('deposit_tsc_not_found', [
                'deposit_id' => $depositId,
                'exttrid'    => $exttrid,
            ]);
            return $deposit;
        }

        $parts   = explode('/', (string)$tsc['transStatus']);
        $primary = $parts[0] ?? '';

        if ($primary === '000') {
            // Run the same success pipeline the callback would. Mark the
            // callback IP as 0.0.0.0 so audit logs distinguish TSC-recovered
            // deposits from real webhook callbacks.
            $this->postSuccess(
                $depositId,
                $deposit,
                (string)$tsc['transStatus'],
                '0.0.0.0',
                $tsc['raw']
            );
            return $this->getById($depositId, $playerId);
        }

        if ($primary === '001') {
            $this->db->execute(
                'UPDATE depositRequest
                    SET status = "failed",
                        failureCode = :code,
                        failureReason = "Mobile Money could not complete the deposit. Please try again or check your account balance.",
                        callbackIp = "0.0.0.0",
                        confirmedAt = NOW()
                  WHERE id = :id AND status IN ("initiated","pending")',
                ['id' => $depositId, 'code' => substr((string)$tsc['transStatus'], 0, 50)]
            );
            $this->logger->info('deposit_tsc_failed', [
                'deposit_id'   => $depositId,
                'exttrid'      => $exttrid,
                'trans_status' => $tsc['transStatus'],
            ]);
            return $this->getById($depositId, $playerId);
        }

        // Unknown / still-pending status code — don't touch the row.
        $this->logger->info('deposit_tsc_still_pending', [
            'deposit_id'   => $depositId,
            'exttrid'      => $exttrid,
            'trans_status' => $tsc['transStatus'],
        ]);
        return $deposit;
    }

    /**
     * Read a single deposit, scoped to the requesting player so they can't
     * peek at other players' transactions.
     *
     * @return array|null
     */
    public function getById(int $depositId, int $playerId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, refNumber, playerId, msisdn, paymentProvider, amountPesewas,
                    status, failureCode, failureReason, walletTxnId,
                    initiatedAt, confirmedAt, expiresAt
             FROM depositRequest
             WHERE id = :id AND playerId = :pid LIMIT 1',
            ['id' => $depositId, 'pid' => $playerId]
        );
    }
}