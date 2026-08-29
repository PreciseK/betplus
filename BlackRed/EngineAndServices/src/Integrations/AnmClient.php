<?php

declare(strict_types=1);

namespace BlackRed\Integrations;

use BlackRed\Bootstrap\Config;
use BlackRed\Database\Connection;
use BlackRed\Logging\Logger;
use RuntimeException;
use Throwable;

/**
 * AppsNMobile (ANM) Orchard API client.
 *
 * Currently implements only the operations needed for Phase 2 signup:
 *   - Account Inquiry (AII) — name lookup against MoMo registry
 *
 * Phase 3 will add:
 *   - Customer to Merchant (CTM) — deposits
 *   - Merchant to Customer (MTC) — withdrawals
 *   - checkTransaction — status query
 *
 * Hardening:
 *   - All requests timeout-bounded (30s default)
 *   - Every request and response logged to momoApiCallLog with status code,
 *     latency, and a redacted snapshot of the body (credentials excluded)
 *   - Name lookups cached for 30 days in nameLookupCache to reduce cost
 *   - HMAC-SHA256 signing per ANM spec
 *
 * Bank code mapping (per user confirmation):
 *   Schema paymentProvider | ANM bank_code
 *   ─────────────────────────────────────
 *   MTN                    | MTN
 *   ATL  (AirtelTigo)      | AIR
 *   TEL  (Telecel)         | VOD   (legacy Vodafone code, ANM still uses it)
 */
class AnmClient
{
    /** Phone-to-MoMo lookup uses bank_code with nw=BNK */
    private const NW_BANK_INQUIRY = 'BNK';

    /** Cache lifetime for successful name lookups */
    private const CACHE_DAYS = 30;

    private readonly AnmSignatureBuilder $signer;
    private readonly string $baseUrl;
    private readonly string $serviceId;
    private readonly int $timeoutSeconds;

    public function __construct(
        Config $config,
        private readonly Connection $db,
        private readonly Logger $logger,
    ) {
        $this->signer = new AnmSignatureBuilder(
            $config->string('ANM_CLIENT_KEY', ''),
            $config->string('ANM_SECRET_KEY', ''),
        );
        $this->baseUrl = rtrim($config->string('ANM_BASE_URL', 'https://orchard-api.anmgw.com'), '/');
        $this->serviceId = $config->string('ANM_SERVICE_ID', '70');
        $this->timeoutSeconds = $config->int('ANM_REQUEST_TIMEOUT_SECONDS', 30);
    }

    /**
     * Translate our schema's paymentProvider code to ANM's bank_code.
     */
    public static function bankCodeFor(string $schemaProvider): string
    {
        return match ($schemaProvider) {
            'MTN' => 'MTN',
            'ATL' => 'AIR',
            'TEL' => 'VOD',
            default => throw new \InvalidArgumentException("Unknown provider: {$schemaProvider}"),
        };
    }

    /**
     * Look up the registered MoMo account holder name for a phone number.
     *
     * @param string $phoneLocal The phone in 0-prefixed local form (e.g. "0244000001")
     * @param string $provider   Schema provider code: MTN | ATL | TEL
     * @return array{name: string, cached: bool, raw: array}  on success
     * @throws AnmLookupException on any failure (the message is safe to surface)
     */
    public function lookupName(string $phoneLocal, string $provider): array
    {
        $bankCode = self::bankCodeFor($provider);

        // Try cache first (key by msisdn + provider as the schema is shaped)
        $cached = $this->cacheLookup($phoneLocal, $provider);
        if ($cached !== null) {
            $this->logger->info('anm_namelookup_cache_hit', [
                'phone' => $phoneLocal,
                'provider' => $provider,
            ]);
            return [
                'name'   => (string)$cached['returnedName'],
                'cached' => true,
                'raw'    => json_decode((string)$cached['rawResponse'], true) ?: [],
            ];
        }

        // Cache miss — call ANM
        $exttrid = 'AII_' . substr(bin2hex(random_bytes(8)), 0, 12); // ANM exttrid max 20 chars
        $payload = [
            'customer_number' => $phoneLocal,
            'service_id'      => $this->serviceId,
            'exttrid'         => $exttrid,
            'trans_type'      => 'AII',
            'bank_code'       => $bankCode,
            'nw'              => self::NW_BANK_INQUIRY,
            'ts'              => gmdate('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/sendRequest', $payload);

        if (!is_array($response['body'])) {
            $this->recordApiCall($exttrid, 'AII', $provider, $payload, $response, 'invalid_json');
            throw new AnmLookupException(
                'Could not parse name lookup response. Please try again.',
                'invalid_response'
            );
        }

        // ANM returns the name in $response['body']['name'] on success
        $name = isset($response['body']['name']) ? trim((string)$response['body']['name']) : '';

        $this->recordApiCall($exttrid, 'AII', $provider, $payload, $response, $name === '' ? 'no_name' : 'ok');

        if ($name === '') {
            $code = isset($response['body']['statusCode']) ? (string)$response['body']['statusCode'] : 'unknown';
            $msg = isset($response['body']['statusMessage']) ? (string)$response['body']['statusMessage'] : '';
            throw new AnmLookupException(
                $msg !== ''
                    ? "Could not find a Mobile Money account on this number ({$msg})."
                    : 'Could not find a Mobile Money account on this number. Please check the number and network.',
                'not_found',
                ['anm_status' => $code]
            );
        }

        // Save to cache
        $this->cacheStore($phoneLocal, $provider, $name, $response['body']);

        return [
            'name'   => $name,
            'cached' => false,
            'raw'    => $response['body'],
        ];
    }

    /**
     * Initiate a Customer-to-Merchant (CTM) MoMo debit. ANM pushes a PIN
     * prompt to the customer's phone; the customer authorises; ANM POSTs
     * the result to our callback URL.
     *
     * Two acceptance gates:
     *   1. ANM returns resp_code "015" — request accepted, queued, prompt
     *      will fire on customer's phone shortly
     *   2. Eventually ANM POSTs to our callback URL with trans_status
     *      000/XX (success) or 001/XX (failure)
     *
     * Per ANM docs (https://docs.anmgw.com/docs-page.html):
     *   - exttrid max length 20 (we use 16 hex chars to stay safe)
     *   - reference max length 10 (truncated by caller; we trust it)
     *   - nw must be the ANM bank code (AIR/VOD/MTN), not our schema's
     *     paymentProvider code (ATL/TEL/MTN)
     *
     * @param string $exttrid       Our 16-hex idempotency key
     * @param string $phoneLocal    Customer phone in 0-prefixed local form
     * @param string $provider      Schema provider code: MTN | ATL | TEL
     * @param string $amountGhs     Amount as decimal GHS string, e.g. "50.00"
     * @param string $reference     ≤10 chars; shown to player on MoMo prompt
     * @param string $callbackUrl   Absolute URL ANM will POST the result to
     * @return array{accepted: bool, statusCode: ?string, message: ?string, raw: array}
     */
    public function initiateCtm(
        string $exttrid,
        string $phoneLocal,
        string $provider,
        string $amountGhs,
        string $reference,
        string $callbackUrl,
    ): array {
        // Translate our schema's paymentProvider to ANM's network code.
        // MTN -> MTN (same), ATL -> AIR, TEL -> VOD. This is the same
        // mapping used for the AII (name lookup) bank_code field.
        $nw = self::bankCodeFor($provider);

        $payload = [
            'client_id'       => $this->serviceId,
            'customer_number' => $phoneLocal,
            'amount'          => $amountGhs,
            'exttrid'         => $exttrid,
            'reference'       => $reference,
            'nw'              => $nw,
            'trans_type'      => 'CTM',
            'callback_url'    => $callbackUrl,
            'ts'              => gmdate('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/sendRequest', $payload);

        $body = is_array($response['body']) ? $response['body'] : [];

        // ANM's documented sync-response shape:
        //   { "resp_code": "015", "resp_desc": "Request successfully received for processing" }
        // Code 015 = accepted-and-queued (CTM happy path)
        // Code 027 = completed synchronously (rare; mostly for AII responses)
        // Anything else = rejected, with the human-readable failure in resp_desc.
        $respCode = isset($body['resp_code']) ? (string)$body['resp_code'] : null;
        $respDesc = isset($body['resp_desc']) ? (string)$body['resp_desc'] : null;

        $accepted = in_array($respCode, ['015', '027'], true);

        $this->recordApiCall($exttrid, 'CTM', $provider, $payload, $response, $accepted ? 'accepted' : 'rejected');

        return [
            'accepted'   => $accepted,
            'statusCode' => $respCode,
            'message'    => $respDesc,
            'raw'        => $body,
        ];
    }

    /**
     * Initiate a Merchant-To-Customer (MTC) MoMo payout. We push money
     * FROM our float TO the customer's mobile money wallet. No PIN is
     * needed from the customer — money arrives and they get an SMS.
     *
     * Two acceptance gates:
     *   1. ANM returns resp_code "015" — request accepted, queued for processing
     *   2. Eventually ANM POSTs to our callback URL with trans_status
     *      000/XX (success — money landed) or 001/XX (failure)
     *
     * Per ANM docs:
     *   - Same /sendRequest endpoint as CTM, distinguished by trans_type
     *   - exttrid max 20 chars
     *   - reference max 10 chars (caller truncates)
     *   - nw must be ANM bank code (AIR/VOD/MTN), not schema code
     *
     * @param string $exttrid       Our 16-hex idempotency key
     * @param string $phoneLocal    Recipient phone in 0-prefixed local form
     * @param string $provider      Schema provider code: MTN | ATL | TEL
     * @param string $amountGhs     Amount as decimal GHS string, e.g. "50.00"
     * @param string $reference     ≤10 chars; appears in any SMS the customer gets
     * @param string $callbackUrl   Absolute URL ANM will POST the result to
     * @return array{accepted: bool, statusCode: ?string, message: ?string, raw: array}
     */
    public function initiateMtc(
        string $exttrid,
        string $phoneLocal,
        string $provider,
        string $amountGhs,
        string $reference,
        string $callbackUrl,
    ): array {
        $nw = self::bankCodeFor($provider);

        $payload = [
            'client_id'       => $this->serviceId,
            'customer_number' => $phoneLocal,
            'amount'          => $amountGhs,
            'exttrid'         => $exttrid,
            'reference'       => $reference,
            'nw'              => $nw,
            'trans_type'      => 'MTC',
            'callback_url'    => $callbackUrl,
            'ts'              => gmdate('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/sendRequest', $payload);
        $body = is_array($response['body']) ? $response['body'] : [];

        $respCode = isset($body['resp_code']) ? (string)$body['resp_code'] : null;
        $respDesc = isset($body['resp_desc']) ? (string)$body['resp_desc'] : null;

        // Same accept logic as CTM — 015 (queued) or 027 (completed) = accepted.
        $accepted = in_array($respCode, ['015', '027'], true);

        $this->recordApiCall($exttrid, 'MTC', $provider, $payload, $response, $accepted ? 'accepted' : 'rejected');

        return [
            'accepted'   => $accepted,
            'statusCode' => $respCode,
            'message'    => $respDesc,
            'raw'        => $body,
        ];
    }

    /**
     * Transaction Status Check (TSC) — query ANM for the current status
     * of a transaction we previously initiated. Per the docs, this is the
     * recovery mechanism for cases where the callback didn't reach us.
     *
     * Endpoint: POST /checkTransaction
     * Sample response body:
     *   {
     *     "trans_status": "000/01",
     *     "trans_ref": "031059294635",
     *     "trans_id": "21870173572",
     *     "message": "SUCCESSFUL"
     *   }
     *
     * The trans_status format is the same as the callback: "XXX/YY",
     * where the first segment is the outcome (000 = success, 001 = failure)
     * and the second is a sub-status. Anything that isn't 000 we treat
     * as either still-pending or failed depending on context.
     *
     * @param string $exttrid Our exttrid (== trans_ref on ANM's side)
     * @return array{
     *   found: bool,
     *   transStatus: ?string,
     *   transRef: ?string,
     *   transId: ?string,
     *   message: ?string,
     *   raw: array
     * }
     */
    public function checkTransaction(string $exttrid): array
    {
        $payload = [
            'exttrid'    => $exttrid,
            'service_id' => $this->serviceId,
            'trans_type' => 'TSC',
            'ts'         => gmdate('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/checkTransaction', $payload);

        $body = is_array($response['body']) ? $response['body'] : [];

        // Two response shapes we may see:
        //  1. Found: { trans_status: "000/01", trans_ref, trans_id, message }
        //  2. Not found / error: { resp_code: "033", resp_desc: "Transaction not found" }
        //                        or some other resp_code variant
        $transStatus = isset($body['trans_status']) ? (string)$body['trans_status'] : null;
        $transRef    = isset($body['trans_ref'])    ? (string)$body['trans_ref']    : null;
        $transId     = isset($body['trans_id'])     ? (string)$body['trans_id']     : null;
        $message     = isset($body['message'])      ? (string)$body['message']
                     : (isset($body['resp_desc'])   ? (string)$body['resp_desc']    : null);

        $found = $transStatus !== null && $transRef !== null;

        // Log this call too — useful for forensics on stuck deposits.
        // We pass 'TSC' as transType but recordApiCall maps it to
        // 'status_query' in the callType column.
        $this->recordApiCall(
            $exttrid, 'TSC', '',
            $payload, $response,
            $found ? 'ok' : 'no_record'
        );

        return [
            'found'       => $found,
            'transStatus' => $transStatus,
            'transRef'    => $transRef,
            'transId'     => $transId,
            'message'     => $message,
            'raw'         => $body,
        ];
    }

    /**
     * POST a JSON body to ANM with HMAC signing.
     *
     * Returns: ['status' => int, 'body' => decoded array | null, 'rawBody' => string, 'latencyMs' => int]
     */
    private function postJson(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            throw new RuntimeException('Failed to encode ANM request body');
        }
        $authHeader = $this->signer->authHeader($jsonBody);

        $startedAt = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            // We DO verify SSL in production. The example code disables it; we don't.
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawBody = curl_exec($ch);
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            $this->logger->warning('anm_curl_error', ['error' => $err, 'latency_ms' => $latencyMs]);
            throw new AnmLookupException(
                'Could not reach the Mobile Money provider. Please try again.',
                'network_error'
            );
        }

        $decoded = null;
        try {
            $decoded = json_decode((string)$rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Leave $decoded as null
        }

        return [
            'status'    => $status,
            'body'      => $decoded,
            'rawBody'   => (string)$rawBody,
            'latencyMs' => $latencyMs,
        ];
    }

    /**
     * Append a row to momoApiCallLog. Best-effort — a logging failure must
     * not break the main flow.
     */
    private function recordApiCall(string $exttrid, string $transType, string $provider, array $request, array $response, string $outcome): void
    {
        try {
            // Map our outcome strings to the schema's ENUM values
            $schemaOutcome = match ($outcome) {
                'ok', 'accepted'      => 'success',
                'no_name', 'rejected', 'no_record' => 'failure',
                'invalid_json'        => 'error',
                'network_error'       => 'error',
                default               => 'error',
            };
            $callType = match ($transType) {
                'AII' => 'name_lookup',
                'CTM' => 'deposit',
                'MTC' => 'withdraw',
                default => 'status_query',
            };

            $this->db->execute(
                'INSERT INTO momoApiCallLog
                    (callType, provider, endpoint, httpMethod, requestRef,
                     requestPayload, responsePayload, httpStatus, outcome, latencyMs,
                     calledAt, respondedAt)
                 VALUES
                    (:ct, :prov, :ep, :method, :ref,
                     :req, :res, :status, :outcome, :lat,
                     NOW(), NOW())',
                [
                    'ct'      => $callType,
                    'prov'    => $provider,
                    'ep'      => '/sendRequest',
                    'method'  => 'POST',
                    'ref'     => $exttrid,
                    'req'     => json_encode($request),
                    'res'     => json_encode($response['body'] ?? null),
                    'status'  => $response['status'] ?? null,
                    'outcome' => $schemaOutcome,
                    'lat'     => $response['latencyMs'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('anm_log_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Look up a previously-cached successful name.
     * Returns array with 'returnedName' and 'rawResponse' keys, or null on miss.
     */
    private function cacheLookup(string $msisdn, string $provider): ?array
    {
        try {
            return $this->db->fetchOne(
                'SELECT returnedName, rawResponse
                 FROM nameLookupCache
                 WHERE msisdn = :msisdn
                   AND provider = :prov
                   AND lookupStatus = :st
                   AND expiresAt > NOW()
                 ORDER BY id DESC LIMIT 1',
                ['msisdn' => $msisdn, 'prov' => $provider, 'st' => 'success']
            );
        } catch (Throwable $e) {
            $this->logger->warning('anm_cache_read_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store a successful lookup in the cache. Best-effort.
     */
    private function cacheStore(string $msisdn, string $provider, string $name, array $rawResponse): void
    {
        try {
            $this->db->execute(
                'INSERT INTO nameLookupCache
                    (msisdn, provider, returnedName, lookupStatus, rawResponse, lookedUpAt, expiresAt)
                 VALUES
                    (:msisdn, :prov, :name, :st, :raw, NOW(), DATE_ADD(NOW(), INTERVAL :days DAY))',
                [
                    'msisdn' => $msisdn,
                    'prov'   => $provider,
                    'name'   => $name,
                    'st'     => 'success',
                    'raw'    => json_encode($rawResponse),
                    'days'   => self::CACHE_DAYS,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('anm_cache_write_failed', ['error' => $e->getMessage()]);
        }
    }
}