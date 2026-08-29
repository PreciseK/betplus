<?php

declare(strict_types=1);

namespace BlackRed\Integrations;

use BlackRed\Logging\Logger;
use BlackRed\Bootstrap\Config;

/**
 * HubtelSmsClient — sends SMS messages via the Hubtel HTTP API.
 *
 * Authenticates with HTTP Basic Auth (CLIENT_ID:CLIENT_SECRET) against
 * https://smsc.hubtel.com/v1/messages/send. Sender ID is a configured
 * short alphanumeric identifier (e.g. "BlackRed") that the recipient sees.
 *
 * This client deliberately does NOT throw on send failure. Instead it
 * returns a structured result so the caller (SmsService) can decide
 * whether to retry, log, or surface to the user — and so OTP issuance
 * doesn't fail just because one SMS didn't go through.
 *
 * Wire-format reference (Hubtel API):
 *   GET https://smsc.hubtel.com/v1/messages/send
 *     ?clientid={id}
 *     &clientsecret={secret}
 *     &from={senderId}
 *     &to={msisdn}            (in 233XXXXXXXXX form, no +)
 *     &content={urlencoded message}
 *
 * Success response: 201 with JSON { status: 0, statusCode: "0", messageId, ... }
 * Failure responses use non-zero status. We treat anything other than
 * HTTP 2xx with status=0 as failure.
 */
final class HubtelSmsClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Send a single SMS message.
     *
     * @param string $msisdn  Canonical 233XXXXXXXXX form (12 digits, no +)
     * @param string $body    Message body (≤ 160 chars for single segment)
     *
     * @return array{
     *   ok: bool,
     *   providerMsgId: ?string,
     *   httpStatus: ?int,
     *   responseCode: ?string,
     *   error: ?string,
     *   raw: ?string
     * }
     */
    public function send(string $msisdn, string $body): array
    {
        $baseUrl     = (string)$this->config->get('HUBTEL_SMS_BASE_URL', 'https://smsc.hubtel.com/v1/messages/send');
        $clientId    = (string)$this->config->get('HUBTEL_SMS_CLIENT_ID', '');
        $clientSecret= (string)$this->config->get('HUBTEL_SMS_CLIENT_SECRET', '');
        $senderId    = (string)$this->config->get('HUBTEL_SMS_SENDER_ID', 'BlackRed');
        $timeoutSecs = (int)$this->config->get('HUBTEL_REQUEST_TIMEOUT_SECONDS', 15);

        if ($clientId === '' || $clientSecret === '') {
            // Don't try to call Hubtel without credentials — log and fail cleanly.
            $this->logger->warning('hubtel_credentials_missing', ['msisdn' => $msisdn]);
            return [
                'ok'            => false,
                'providerMsgId' => null,
                'httpStatus'    => null,
                'responseCode'  => null,
                'error'         => 'credentials_missing',
                'raw'           => null,
            ];
        }

        // Hubtel expects msisdn in 233XXXXXXXXX form. Strip a leading + if present.
        $to = ltrim($msisdn, '+');

        $url = $baseUrl . '?' . http_build_query([
            'clientid'     => $clientId,
            'clientsecret' => $clientSecret,
            'from'         => $senderId,
            'to'           => $to,
            'content'      => $body,
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->fail('curl_init_failed', null, null);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSecs,
            CURLOPT_CONNECTTIMEOUT => max(5, (int)floor($timeoutSecs / 2)),
            CURLOPT_FOLLOWLOCATION => false,
            // Don't put credentials in HTTP headers when they're already in query;
            // some Hubtel deployments require query-string auth specifically.
            CURLOPT_USERAGENT      => 'BlackRed/1.0',
        ]);

        $rawResponse = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            $this->logger->warning('hubtel_curl_error', [
                'msisdn' => $msisdn,
                'error'  => $curlError,
            ]);
            return $this->fail('network_error', $httpStatus ?: null, null);
        }

        $rawString = (string)$rawResponse;

        // Parse response. Hubtel returns JSON on success and sometimes plaintext on error.
        $parsed = json_decode($rawString, true);
        $providerMsgId = null;
        $responseCode = null;

        if (is_array($parsed)) {
            $statusField = $parsed['status'] ?? $parsed['statusCode'] ?? null;
            $responseCode = $statusField !== null ? (string)$statusField : null;
            $providerMsgId = isset($parsed['messageId']) ? (string)$parsed['messageId'] : null;
        }

        $is2xx = $httpStatus >= 200 && $httpStatus < 300;
        $isAccepted = $is2xx && ($responseCode === '0' || $responseCode === null && $is2xx);

        if (!$isAccepted) {
            $this->logger->warning('hubtel_send_rejected', [
                'msisdn'       => $msisdn,
                'httpStatus'   => $httpStatus,
                'responseCode' => $responseCode,
                'raw'          => substr($rawString, 0, 500),
            ]);
            return [
                'ok'            => false,
                'providerMsgId' => $providerMsgId,
                'httpStatus'    => $httpStatus,
                'responseCode'  => $responseCode,
                'error'         => 'rejected',
                'raw'           => $rawString,
            ];
        }

        $this->logger->info('hubtel_send_ok', [
            'msisdn'        => $msisdn,
            'providerMsgId' => $providerMsgId,
            'httpStatus'    => $httpStatus,
        ]);

        return [
            'ok'            => true,
            'providerMsgId' => $providerMsgId,
            'httpStatus'    => $httpStatus,
            'responseCode'  => $responseCode,
            'error'         => null,
            'raw'           => $rawString,
        ];
    }

    /** @return array{ok:false, providerMsgId:null, httpStatus:?int, responseCode:?string, error:string, raw:null} */
    private function fail(string $error, ?int $httpStatus, ?string $responseCode): array
    {
        return [
            'ok'            => false,
            'providerMsgId' => null,
            'httpStatus'    => $httpStatus,
            'responseCode'  => $responseCode,
            'error'         => $error,
            'raw'           => null,
        ];
    }
}