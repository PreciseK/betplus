<?php

declare(strict_types=1);

namespace BlackRed\Sms;

use BlackRed\Database\Connection;
use BlackRed\Integrations\HubtelSmsClient;
use BlackRed\Logging\Logger;

/**
 * SmsService — application-layer SMS sending.
 *
 * Responsibilities:
 *   - Insert a row in smsLog BEFORE attempting to send (queued state).
 *     This guarantees every SMS attempt has a durable audit trail even if
 *     the network call fails partway.
 *   - Call HubtelSmsClient to do the actual send.
 *   - Update the smsLog row with the outcome (sent / failed + reason).
 *
 * This service is fail-safe: SMS failure is logged but does not raise an
 * exception. Callers (e.g. OtpService) make sending best-effort; the user
 * always has the *920*995# fallback to retrieve their code.
 */
final class SmsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly HubtelSmsClient $hubtel,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Send an OTP SMS.
     *
     * @param string  $msisdn        Canonical 233XXXXXXXXX form
     * @param string  $messageBody   Body text (≤ 500 chars; SMS gateways may split)
     * @param ?int    $playerId      Player.id when known (for forgot-PIN); NULL for signup
     * @param ?string $relatedTable  Optional FK-ish reference table (e.g. 'signupSession')
     * @param ?int    $relatedId     Optional FK-ish reference id
     *
     * @return bool  true if the SMS was accepted by Hubtel; false otherwise
     */
    public function sendOtp(
        string $msisdn,
        string $messageBody,
        ?int $playerId = null,
        ?string $relatedTable = null,
        ?int $relatedId = null,
    ): bool {
        return $this->send(
            msisdn:       $msisdn,
            messageBody:  $messageBody,
            category:     'otp',
            playerId:     $playerId,
            relatedTable: $relatedTable,
            relatedId:    $relatedId,
        );
    }

    /**
     * Generic SMS send — used internally for OTP today; extendable for other
     * categories (deposit_confirm, withdrawal_confirm, security_alert, ...).
     */
    public function send(
        string $msisdn,
        string $messageBody,
        string $category,
        ?int $playerId = null,
        ?string $relatedTable = null,
        ?int $relatedId = null,
    ): bool {
        // 1. Record the attempt as queued.
        $smsLogId = $this->db->insert(
            'INSERT INTO smsLog
                (playerId, msisdn, category, messageBody, relatedTable, relatedId,
                 provider, status, queuedAt)
             VALUES
                (:pid, :msisdn, :cat, :body, :rt, :rid, :prov, "queued", NOW())',
            [
                'pid'    => $playerId,
                'msisdn' => $msisdn,
                'cat'    => $category,
                'body'   => mb_substr($messageBody, 0, 500),
                'rt'     => $relatedTable,
                'rid'    => $relatedId,
                'prov'   => 'hubtel',
            ]
        );

        // 2. Send.
        $result = $this->hubtel->send($msisdn, $messageBody);

        // 3. Update the log row with the outcome.
        if ($result['ok']) {
            $this->db->execute(
                'UPDATE smsLog
                    SET status = "sent",
                        providerMsgId = :mid,
                        sentAt = NOW()
                  WHERE id = :id',
                [
                    'mid' => $result['providerMsgId'],
                    'id'  => $smsLogId,
                ]
            );
            return true;
        }

        $failureReason = ($result['error'] ?? 'unknown') . (
            $result['responseCode'] !== null ? (' (code=' . $result['responseCode'] . ')') : ''
        );
        $this->db->execute(
            'UPDATE smsLog
                SET status = "failed",
                    providerMsgId = :mid,
                    failureReason = :reason
              WHERE id = :id',
            [
                'mid'    => $result['providerMsgId'],
                'reason' => mb_substr($failureReason, 0, 500),
                'id'     => $smsLogId,
            ]
        );

        $this->logger->warning('sms_send_failed', [
            'smsLogId'    => $smsLogId,
            'msisdn'      => $msisdn,
            'category'    => $category,
            'reason'      => $failureReason,
        ]);

        return false;
    }
}