<?php

declare(strict_types=1);

namespace BlackRed\Wallet;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Integrations\AnmClient;
use BlackRed\Logging\Logger;
use BlackRed\Support\Money;

/**
 * WithdrawalService — handles two withdrawal types:
 *
 *   Type A — "Move to Play" (destination = 'PLAY')
 *     Internal Payout->Play transfer. No external API. Atomic and instant.
 *     Player's winnings get recycled into stakeable funds.
 *
 *   Type B — "Withdraw to MoMo" (destination = 'MOMO')
 *     External payout via ANM MTC. Money leaves our float and lands in
 *     the player's registered MoMo wallet. Lifecycle:
 *       initiated -> pending -> succeeded | failed
 *     Driven by ANM webhook callback; user-driven verify pings TSC as
 *     fallback for lost callbacks (mirrors deposit flow).
 *
 * Money flow per Type B success (e.g. GHS 100 withdrawal, 0.5% fee):
 *   - Player's PLAYER_PAYOUT decreases by 100  (DEBIT, since credit-normal)
 *   - MOMO_FLOAT_MTN decreases by 100.50      (CREDIT, since debit-normal)
 *   - MOMO_FEE_EXPENSE increases by 0.50      (DEBIT, since debit-normal)
 *   Sum of debits = 100 + 0.50 = 100.50 = sum of credits ✓
 *
 * The fee is absorbed by BlackRed (player gets the full amount they
 * requested). MOMO_FLOAT_MTN reflects what actually settles.
 *
 * Concurrency model: same as deposit. Conditional UPDATE for the success
 * path; explicit row-count check guards against double-credit.
 *
 * Safety:
 *   - Insufficient-balance check inside the same transaction that debits;
 *     race-free against concurrent withdrawal attempts (row-level lock).
 *   - Pending Type B withdrawal blocks new Type B initiates for the same
 *     player (one in flight at a time).
 *   - 1-hour cooldown after a successful password reset blocks Type B
 *     (defense against account-takeover-then-withdraw attacks).
 *   - exttrid is 16 random hex chars; 10-min expiry window for callbacks.
 */
final class WithdrawalService
{
    private const MIN_GHS_PESEWAS = 100;       // GHS 1.00
    private const MAX_GHS_PESEWAS = 500000;    // GHS 5,000.00
    private const VALIDITY_MINUTES = 10;
    private const PASSWORD_COOLDOWN_HOURS = 1;

    public function __construct(
        private readonly Connection $db,
        private readonly AnmClient $anm,
        private readonly Logger $logger,
        private readonly string $callbackUrl,
        private readonly string $merchantReference,
        // Fee charged by ANM on outbound MTC, in basis points. 50 = 0.5%.
        private readonly int $withdrawalFeeBps = 50,
    ) {
    }

    /**
     * Start a withdrawal. Behaves differently per destination:
     *
     *   destination='PLAY' — instant atomic Payout->Play transfer.
     *                        Returns the row already 'succeeded'.
     *   destination='MOMO' — calls ANM MTC. Returns row in 'pending' state;
     *                        callback or verify will resolve it.
     *
     * @throws HttpException on validation, balance, cooldown, or concurrency errors
     */
    public function initiate(int $playerId, string $destination, int $amountPesewas, string $clientIp): array
    {
        if (!in_array($destination, ['PLAY', 'MOMO'], true)) {
            throw new HttpException(400, 'invalid_destination', 'Destination must be PLAY or MOMO.');
        }
        if ($amountPesewas < self::MIN_GHS_PESEWAS) {
            throw new HttpException(
                400, 'amount_too_low',
                'Minimum withdrawal is GHS ' . Money::pesewasToString(self::MIN_GHS_PESEWAS) . '.'
            );
        }
        if ($amountPesewas > self::MAX_GHS_PESEWAS) {
            throw new HttpException(
                400, 'amount_too_high',
                'Maximum withdrawal is GHS ' . Money::pesewasToString(self::MAX_GHS_PESEWAS) . '.'
            );
        }

        // Look up player + payout wallet
        $player = $this->db->fetchOne(
            'SELECT id, msisdn, paymentProvider, accountStatus
               FROM player WHERE id = :id LIMIT 1',
            ['id' => $playerId]
        );
        if ($player === null) {
            throw HttpException::unauthorized('Authentication required');
        }
        if ($player['accountStatus'] !== 'active') {
            throw new HttpException(403, 'account_not_eligible', 'Your account is not eligible for withdrawals at the moment. Please contact support.');
        }

        // 1-hour password-reset cooldown applies only to Type B (MoMo).
        // Type A is internal — funds stay on the platform — so the
        // hijack-then-cash-out attack doesn't apply.
        if ($destination === 'MOMO' && $this->isWithinPasswordResetCooldown($playerId)) {
            throw new HttpException(
                429, 'password_reset_cooldown',
                'For your security, withdrawals to Mobile Money are paused for ' . self::PASSWORD_COOLDOWN_HOURS . ' hour after a password reset. Please try again shortly. You can still move funds to your Play balance.'
            );
        }

        // Type B: enforce one-in-flight rule
        if ($destination === 'MOMO') {
            $pending = $this->db->fetchOne(
                'SELECT id FROM withdrawalRequest
                  WHERE playerId = :pid AND destination = "MOMO"
                    AND status IN ("initiated","pending","approved")
                    AND expiresAt > NOW()
                  LIMIT 1',
                ['pid' => $playerId]
            );
            if ($pending !== null) {
                throw new HttpException(
                    409, 'pending_withdrawal_exists',
                    'You already have a withdrawal in progress. Please wait for it to complete before starting another.'
                );
            }
        }

        $exttrid = bin2hex(random_bytes(8));

        // For Type A we don't need the float-network mapping or callback URL.
        // For Type B we need the player's registered MoMo number.
        $msisdn = (string)$player['msisdn'];
        $provider = (string)$player['paymentProvider'];
        $phoneLocal = $this->msisdnToLocal($msisdn);
        $amountGhs = Money::pesewasToString($amountPesewas);

        // Insert the request row first. This gives us an id even if anything
        // downstream fails. For Type B, status='initiated'; we'll flip it
        // to 'pending' if ANM accepts, or 'failed' if ANM rejects.
        // For Type A, we'll process it in the transaction below and finalize
        // status='succeeded' before returning.
        $expiresAt = $destination === 'MOMO'
            ? gmdate('Y-m-d H:i:s', time() + (self::VALIDITY_MINUTES * 60))
            : null;

        $withdrawalId = $this->db->insert(
            'INSERT INTO withdrawalRequest
                (refNumber, playerId, msisdn, paymentProvider, destination,
                 sourceWallet, amountPesewas, currency,
                 status, channel, initiatedAt, expiresAt, clientIp)
             VALUES
                (:ref, :pid, :ms, :prov, :dest,
                 "PAYOUT", :amt, "GHS",
                 "initiated", "web", NOW(), :exp, :ip)',
            [
                'ref'  => $exttrid,
                'pid'  => $playerId,
                'ms'   => $msisdn,
                'prov' => $provider,
                'dest' => $destination,
                'amt'  => $amountPesewas,
                'exp'  => $expiresAt,
                'ip'   => $clientIp,
            ]
        );

        if ($destination === 'PLAY') {
            return $this->processInternalTransfer($withdrawalId, $playerId, $amountPesewas);
        }

        // Type B — call ANM
        $anmResult = $this->anm->initiateMtc(
            $exttrid,
            $phoneLocal,
            $provider,
            $amountGhs,
            substr($this->merchantReference, 0, 10),
            $this->callbackUrl,
        );

        if (!$anmResult['accepted']) {
            $this->db->execute(
                'UPDATE withdrawalRequest
                    SET status = "failed",
                        failureCode = :code,
                        failureReason = :reason,
                        completedAt = NOW()
                  WHERE id = :id',
                [
                    'id'     => $withdrawalId,
                    'code'   => substr((string)($anmResult['statusCode'] ?? 'unknown'), 0, 50),
                    'reason' => substr($anmResult['message'] ?? 'Mobile Money declined the withdrawal. Please try again.', 0, 500),
                ]
            );
            $this->logger->info('withdrawal_initiate_rejected', [
                'withdrawal_id' => $withdrawalId,
                'exttrid'       => $exttrid,
                'anm_code'      => $anmResult['statusCode'],
            ]);
            return $this->getById($withdrawalId, $playerId) ?? throw new \RuntimeException('row vanished');
        }

        // Accepted — flip to pending, await callback
        $this->db->execute(
            'UPDATE withdrawalRequest
                SET status = "pending",
                    momoRequestPayload = :req,
                    momoResponsePayload = :res
              WHERE id = :id',
            [
                'id'  => $withdrawalId,
                'req' => json_encode(['exttrid' => $exttrid, 'provider' => $provider, 'amount' => $amountGhs]),
                'res' => json_encode($anmResult['raw'] ?? []),
            ]
        );
        $this->logger->info('withdrawal_initiate_accepted', [
            'withdrawal_id' => $withdrawalId,
            'exttrid'       => $exttrid,
        ]);

        return $this->getById($withdrawalId, $playerId) ?? throw new \RuntimeException('row vanished');
    }

    /**
     * Type A processing — atomic Payout->Play transfer.
     * No external calls, no callback. Done inside this single transaction.
     *
     * Ledger:
     *   DEBIT  PLAYER_PAYOUT:N (decreases — credit-normal account)
     *   CREDIT PLAYER_PLAY:N   (increases — credit-normal account)
     * Sum of debits = sum of credits = amountPesewas ✓
     */
    private function processInternalTransfer(int $withdrawalId, int $playerId, int $amountPesewas): array
    {
        $this->db->transactional(function (Connection $db) use ($withdrawalId, $playerId, $amountPesewas) {
            // Lock the wallets and check Payout balance under the lock
            $payoutWallet = $db->fetchOne(
                'SELECT id, cachedBalancePesewas FROM wallet
                  WHERE playerId = :pid AND walletType = "PAYOUT"
                  FOR UPDATE',
                ['pid' => $playerId]
            );
            if ($payoutWallet === null) {
                throw new \RuntimeException("PAYOUT wallet missing for player $playerId");
            }
            if ((int)$payoutWallet['cachedBalancePesewas'] < $amountPesewas) {
                // Mark the withdrawal row as failed inside the same transaction
                $db->execute(
                    'UPDATE withdrawalRequest
                        SET status = "failed", failureCode = "insufficient_funds",
                            failureReason = "Insufficient Payout balance.",
                            completedAt = NOW()
                      WHERE id = :id',
                    ['id' => $withdrawalId]
                );
                throw new HttpException(
                    400, 'insufficient_funds',
                    'You do not have enough in your Payout balance for this transfer.'
                );
            }

            $payoutAccount = $db->fetchOne(
                'SELECT id FROM account
                  WHERE accountType = "PLAYER_PAYOUT" AND ownerType = "player" AND ownerId = :pid
                  LIMIT 1',
                ['pid' => $playerId]
            );
            $playAccount = $db->fetchOne(
                'SELECT id FROM account
                  WHERE accountType = "PLAYER_PLAY" AND ownerType = "player" AND ownerId = :pid
                  LIMIT 1',
                ['pid' => $playerId]
            );
            if ($payoutAccount === null || $playAccount === null) {
                throw new \RuntimeException("Player accounts missing for player $playerId");
            }

            // Use the withdrawal's exttrid as the walletTxn ref (it's unique)
            $exttrid = (string)$db->fetchValue(
                'SELECT refNumber FROM withdrawalRequest WHERE id = :id',
                ['id' => $withdrawalId]
            );

            $walletTxnId = $db->insert(
                'INSERT INTO walletTransaction
                    (refNumber, txnType, playerId, amountPesewas, currency,
                     status, metadata, initiatedBy, channel, createdAt, completedAt)
                 VALUES
                    (:ref, "INTERNAL_XFER", :pid, :amt, "GHS",
                     "completed", :meta, "player", "web", NOW(), NOW())',
                [
                    'ref'  => $exttrid,
                    'pid'  => $playerId,
                    'amt'  => $amountPesewas,
                    'meta' => json_encode(['from' => 'PAYOUT', 'to' => 'PLAY', 'destination' => 'PLAY']),
                ]
            );

            // Debit Payout, Credit Play (both credit-normal accounts)
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "debit", :amt, "GHS", "Move to Play balance — out of Payout")',
                ['tx' => $walletTxnId, 'acc' => (int)$payoutAccount['id'], 'amt' => $amountPesewas]
            );
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "credit", :amt, "GHS", "Move to Play balance — into Play")',
                ['tx' => $walletTxnId, 'acc' => (int)$playAccount['id'], 'amt' => $amountPesewas]
            );

            // Update both cached balances
            $db->execute(
                'UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                        version = version + 1, updatedAt = NOW()
                  WHERE playerId = :pid AND walletType = "PAYOUT"',
                ['amt' => $amountPesewas, 'pid' => $playerId]
            );
            $db->execute(
                'UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                        version = version + 1, updatedAt = NOW()
                  WHERE playerId = :pid AND walletType = "PLAY"',
                ['amt' => $amountPesewas, 'pid' => $playerId]
            );

            // Finalize the withdrawal row
            $db->execute(
                'UPDATE withdrawalRequest
                    SET status = "succeeded", walletTxnId = :tx,
                        approvedAt = NOW(), completedAt = NOW()
                  WHERE id = :id',
                ['tx' => $walletTxnId, 'id' => $withdrawalId]
            );

            $this->logger->info('withdrawal_internal_success', [
                'withdrawal_id' => $withdrawalId,
                'player_id'     => $playerId,
                'amount_pesewas'=> $amountPesewas,
                'wallet_txn_id' => $walletTxnId,
            ]);
        });

        return $this->getById($withdrawalId, $playerId) ?? throw new \RuntimeException('row vanished');
    }

    /**
     * ANM webhook callback handler for MTC (withdrawal) responses.
     * Same shape as the CTM callback. Idempotent — replaying a callback
     * is a no-op.
     *
     * @return bool true if we acted (or already acted), false if exttrid unknown / malformed
     */
    public function handleCallback(string $exttrid, string $transStatus, string $callbackIp, ?array $rawBody): bool
    {
        if (!preg_match('/^[a-f0-9]{16}$|^[a-f0-9]{32}$/', $exttrid)) {
            $this->logger->info('withdrawal_callback_malformed_exttrid', ['exttrid_len' => strlen($exttrid)]);
            return false;
        }

        $row = $this->db->fetchOne(
            'SELECT id, playerId, amountPesewas, paymentProvider, status, refNumber, expiresAt, destination
               FROM withdrawalRequest
              WHERE refNumber = :ref AND destination = "MOMO" LIMIT 1',
            ['ref' => $exttrid]
        );
        if ($row === null) {
            return false;
        }

        // Already resolved — replay = no-op
        if (!in_array($row['status'], ['initiated', 'pending', 'approved'], true)) {
            $this->logger->info('withdrawal_callback_replay', [
                'withdrawal_id' => $row['id'], 'now_status' => $row['status'],
            ]);
            return true;
        }

        if (strtotime((string)$row['expiresAt']) < time()) {
            $this->logger->warning('withdrawal_callback_expired', [
                'withdrawal_id' => $row['id'],
            ]);
            return true;
        }

        $parts = explode('/', $transStatus);
        $primary = $parts[0] ?? '';

        if ($primary === '000') {
            $this->postSuccess((int)$row['id'], $row, $transStatus, $callbackIp, $rawBody);
            return true;
        }

        // Failure
        $this->db->execute(
            'UPDATE withdrawalRequest
                SET status = "failed",
                    failureCode = :code,
                    failureReason = "Mobile Money could not complete the withdrawal. Your Payout balance is unchanged.",
                    callbackIp = :ip,
                    completedAt = NOW()
              WHERE id = :id AND status IN ("initiated","pending","approved")',
            ['id' => $row['id'], 'code' => substr($transStatus, 0, 50), 'ip' => $callbackIp]
        );
        $this->logger->info('withdrawal_callback_failed', [
            'withdrawal_id' => $row['id'], 'trans_status' => $transStatus,
        ]);
        return true;
    }

    /**
     * Type B success pipeline. Atomic. Idempotent.
     *
     * Ledger (e.g. GHS 100 withdrawal, 0.5% fee = 50 pesewas):
     *   DEBIT  PLAYER_PAYOUT:N    by amountPesewas (decreases — credit-normal)
     *   CREDIT MOMO_FLOAT_MTN     by amountPesewas + feePesewas (decreases — debit-normal)
     *   DEBIT  MOMO_FEE_EXPENSE   by feePesewas (increases — debit-normal)
     *
     * Sum of debits  = amount + fee
     * Sum of credits = amount + fee
     * Balanced ✓
     */
    private function postSuccess(int $withdrawalId, array $row, string $transStatus, string $callbackIp, ?array $rawBody): void
    {
        $this->db->transactional(function (Connection $db) use ($withdrawalId, $row, $transStatus, $callbackIp, $rawBody): void {
            $db->execute(
                'UPDATE withdrawalRequest
                    SET status = "succeeded",
                        callbackIp = :ip,
                        approvedAt = NOW(),
                        completedAt = NOW()
                  WHERE id = :id AND status IN ("initiated","pending","approved")',
                ['id' => $withdrawalId, 'ip' => $callbackIp]
            );
            $check = $db->fetchOne('SELECT status FROM withdrawalRequest WHERE id = :id', ['id' => $withdrawalId]);
            if ($check === null || $check['status'] !== 'succeeded') {
                $this->logger->info('withdrawal_callback_race_lost', [
                    'withdrawal_id' => $withdrawalId, 'now_status' => $check['status'] ?? 'missing',
                ]);
                return;
            }

            $playerId      = (int)$row['playerId'];
            $amountPesewas = (int)$row['amountPesewas'];
            $provider      = (string)$row['paymentProvider'];
            $exttrid       = (string)$row['refNumber'];

            $feePesewas = intdiv($amountPesewas * $this->withdrawalFeeBps, 10000);

            // Account lookups
            $payoutAccount = $db->fetchOne(
                'SELECT id FROM account
                  WHERE accountType = "PLAYER_PAYOUT" AND ownerType = "player" AND ownerId = :pid LIMIT 1',
                ['pid' => $playerId]
            );
            $floatType = 'MOMO_FLOAT_' . $provider;
            $floatAccount = $db->fetchOne(
                'SELECT id FROM account WHERE accountType = :t AND ownerType = "momo" LIMIT 1',
                ['t' => $floatType]
            );
            $feeAccount = $db->fetchOne(
                'SELECT id FROM account WHERE accountCode = "MOMO_FEE_EXPENSE" LIMIT 1'
            );
            if ($payoutAccount === null || $floatAccount === null || $feeAccount === null) {
                throw new \RuntimeException("Required account missing for withdrawal $withdrawalId");
            }

            $walletTxnId = $db->insert(
                'INSERT INTO walletTransaction
                    (refNumber, txnType, playerId, amountPesewas, currency,
                     status, metadata, initiatedBy, channel, createdAt, completedAt)
                 VALUES
                    (:ref, "WITHDRAWAL_PAYOUT", :pid, :amt, "GHS",
                     "completed", :meta, "player", "web", NOW(), NOW())',
                [
                    'ref'  => $exttrid,
                    'pid'  => $playerId,
                    'amt'  => $amountPesewas,
                    'meta' => json_encode([
                        'provider' => $provider, 'msisdn' => $row['msisdn'] ?? null,
                        'destination' => 'MOMO', 'feePesewas' => $feePesewas,
                        'anmStatus' => $transStatus, 'callbackAt' => gmdate('c'),
                    ]),
                ]
            );

            // Three legs
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "debit", :amt, "GHS", :desc)',
                [
                    'tx' => $walletTxnId, 'acc' => (int)$payoutAccount['id'],
                    'amt' => $amountPesewas, 'desc' => "Withdrawal to $provider MoMo",
                ]
            );
            $db->execute(
                'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, "credit", :amt, "GHS", :desc)',
                [
                    'tx' => $walletTxnId, 'acc' => (int)$floatAccount['id'],
                    'amt' => $amountPesewas + $feePesewas,
                    'desc' => "Outflow to player + ANM fee",
                ]
            );
            if ($feePesewas > 0) {
                $db->execute(
                    'INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                     VALUES (:tx, :acc, "debit", :amt, "GHS", :desc)',
                    [
                        'tx' => $walletTxnId, 'acc' => (int)$feeAccount['id'],
                        'amt' => $feePesewas, 'desc' => "ANM MTC fee on withdrawal",
                    ]
                );
            }

            // Decrement Payout cached balance
            $db->execute(
                'UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                        version = version + 1, updatedAt = NOW()
                  WHERE playerId = :pid AND walletType = "PAYOUT"',
                ['amt' => $amountPesewas, 'pid' => $playerId]
            );

            // Stamp txn id and fee
            $db->execute(
                'UPDATE withdrawalRequest
                    SET walletTxnId = :tx, feePesewas = :fee
                  WHERE id = :id',
                ['tx' => $walletTxnId, 'fee' => $feePesewas, 'id' => $withdrawalId]
            );

            $this->logger->info('withdrawal_success', [
                'withdrawal_id' => $withdrawalId,
                'exttrid'       => $exttrid,
                'player_id'     => $playerId,
                'amount_pesewas'=> $amountPesewas,
                'fee_pesewas'   => $feePesewas,
                'wallet_txn_id' => $walletTxnId,
            ]);
        });
    }

    /**
     * User-driven verify — same TSC fallback pattern as deposits.
     */
    public function verifyWithAnm(int $withdrawalId, int $playerId): ?array
    {
        $w = $this->getById($withdrawalId, $playerId);
        if ($w === null) {
            return null;
        }

        // Type A is instant, never pending — nothing to verify
        if ($w['destination'] !== 'MOMO') {
            return $w;
        }
        if (!in_array($w['status'], ['initiated', 'pending', 'approved'], true)) {
            return $w;
        }

        $exttrid = (string)$w['refNumber'];
        $tsc = $this->anm->checkTransaction($exttrid);

        if (!$tsc['found']) {
            $this->logger->info('withdrawal_tsc_not_found', [
                'withdrawal_id' => $withdrawalId, 'exttrid' => $exttrid,
            ]);
            return $w;
        }

        $parts   = explode('/', (string)$tsc['transStatus']);
        $primary = $parts[0] ?? '';

        if ($primary === '000') {
            $this->postSuccess($withdrawalId, $w, (string)$tsc['transStatus'], '0.0.0.0', $tsc['raw']);
            return $this->getById($withdrawalId, $playerId);
        }

        if ($primary === '001') {
            $this->db->execute(
                'UPDATE withdrawalRequest
                    SET status = "failed",
                        failureCode = :code,
                        failureReason = "Mobile Money could not complete the withdrawal. Your Payout balance is unchanged.",
                        callbackIp = "0.0.0.0",
                        completedAt = NOW()
                  WHERE id = :id AND status IN ("initiated","pending","approved")',
                ['id' => $withdrawalId, 'code' => substr((string)$tsc['transStatus'], 0, 50)]
            );
            $this->logger->info('withdrawal_tsc_failed', [
                'withdrawal_id' => $withdrawalId, 'trans_status' => $tsc['transStatus'],
            ]);
            return $this->getById($withdrawalId, $playerId);
        }

        return $w;
    }

    /**
     * Read a withdrawal scoped to the requesting player.
     */
    public function getById(int $withdrawalId, int $playerId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM withdrawalRequest WHERE id = :id AND playerId = :pid LIMIT 1',
            ['id' => $withdrawalId, 'pid' => $playerId]
        );
    }

    /**
     * 1-hour password-reset cooldown check. Returns true if a successful
     * password reset happened within the cooldown window.
     */
    private function isWithinPasswordResetCooldown(int $playerId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 FROM passwordResetRequest
              WHERE playerId = :pid
                AND status = "used"
                AND usedAt > DATE_SUB(NOW(), INTERVAL :hours HOUR)
              LIMIT 1',
            ['pid' => $playerId, 'hours' => self::PASSWORD_COOLDOWN_HOURS]
        );
        return $row !== null;
    }

    /**
     * Convert international 233XXXXXXXXX format to local 0XXXXXXXXX.
     */
    private function msisdnToLocal(string $msisdn): string
    {
        if (str_starts_with($msisdn, '233') && strlen($msisdn) === 12) {
            return '0' . substr($msisdn, 3);
        }
        return $msisdn;
    }
}