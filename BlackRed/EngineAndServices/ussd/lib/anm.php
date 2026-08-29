<?php
/**
 * ANM Orchard client — USSD slim version.
 *
 * Implements the calls we need for USSD:
 *   - anmNameLookup(phone, naloNetwork)  — PR 3 (AII)
 *   - (PR 5/6) anmDeposit / anmWithdraw — to be added
 *
 * Reference: https://docs.anmgw.com/docs-page.html#accountInquiry
 *
 * AII PAYLOAD (verified working with service_id=70):
 *   {
 *     "customer_number": "0244000000",
 *     "service_id":      "70",
 *     "exttrid":         "AII<timestamp><rand>",
 *     "trans_type":      "AII",
 *     "bank_code":       "MTN" | "VOD" | "AIR",   ← carries the actual network
 *     "nw":              "BNK",                    ← constant for ALL AII calls
 *     "ts":              "<UTC Y-m-d H:i:s>"
 *   }
 *
 * AII RESPONSE (success):
 *   { "resp_code": "027", "resp_desc": "...", "name": "KOFI MENSAH" }
 *
 * AII RESPONSE (failure):
 *   { "resp_code": "<something else>", "resp_desc": "<why>" }
 *
 * Authentication:
 *   Authorization: <client_key>:<HMAC-SHA256(json_body, secret_key)>
 *
 * Network mapping from Nalo to ANM bank_code:
 *   Nalo "MTN"        → "MTN"
 *   Nalo "TELECEL"    → "VOD"
 *   Nalo "AIRTELTIGO" → "AIR"
 *
 * Caching: successful lookups stored in nameLookupCache table (shared with web
 * app), 30-day TTL. Key = (msisdn, provider).
 */

/**
 * Map Nalo's NETWORK field to ANM's bank_code.
 *
 * Nalo can also send "ATL" for AirtelTigo on some networks — we accept both.
 * Returns null if we don't recognize the value (the caller decides what to do).
 */
function anmNetworkToBankCode(string $naloNetwork): ?string
{
    return match (strtoupper(trim($naloNetwork))) {
        'MTN'                       => 'MTN',
        'TELECEL', 'VODAFONE', 'VOD' => 'VOD',
        'AIRTELTIGO', 'AIR', 'ATL'   => 'AIR',
        default => null,
    };
}

/**
 * Map ANM bank_code back to our schema's paymentProvider enum value.
 * Used when storing the player record so the value matches what the web
 * app uses (MTN | TEL | ATL).
 */
function anmBankCodeToProvider(string $bankCode): string
{
    return match (strtoupper($bankCode)) {
        'MTN' => 'MTN',
        'VOD' => 'TEL',
        'AIR' => 'ATL',
        default => throw new InvalidArgumentException("Unknown bank_code: $bankCode"),
    };
}

/**
 * Build the Authorization header value for an ANM request.
 *
 *   header = client_key + ":" + HMAC-SHA256(json_body, secret_key)
 */
function anmSignBody(string $jsonBody): string
{
    global $USSD_CONFIG;
    $clientKey = (string)($USSD_CONFIG['anm']['client_key'] ?? '');
    $secretKey = (string)($USSD_CONFIG['anm']['secret_key'] ?? '');
    if ($clientKey === '' || $secretKey === '') {
        throw new RuntimeException('ANM credentials not configured');
    }
    return $clientKey . ':' . hash_hmac('sha256', $jsonBody, $secretKey);
}

/**
 * Look up the MoMo account name for a phone + network.
 *
 * @param  string $phoneLocal   Phone in 0XXXXXXXXX form (NOT canonical 233 form)
 * @param  string $bankCode     ANM bank_code: 'MTN' | 'VOD' | 'AIR'
 * @return string               The registered name as ANM returned it
 *
 * @throws RuntimeException with a user-friendly message on failure
 */
function anmNameLookup(string $phoneLocal, string $bankCode): string
{
    // 1. Cache check — shared with web app for cost/latency reasons
    $cached = anmCacheGet($phoneLocal, $bankCode);
    if ($cached !== null) {
        ussdLog('ANM_LOOKUP_CACHE_HIT', ['phone' => $phoneLocal, 'bank_code' => $bankCode]);
        return $cached;
    }

    // 2. Build request
    global $USSD_CONFIG;
    $cfg       = $USSD_CONFIG['anm'];
    $serviceId = (string)($cfg['service_id'] ?? '70');
    $baseUrl   = rtrim((string)($cfg['base_url'] ?? ''), '/');
    $timeout   = (int)($cfg['timeout_sec'] ?? 180);

    if ($baseUrl === '') {
        throw new RuntimeException('ANM base URL not configured');
    }

    $exttrid = 'AII' . date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 3);

    $payload = [
        'customer_number' => $phoneLocal,
        'service_id'      => $serviceId,
        'exttrid'         => $exttrid,
        'trans_type'      => 'AII',
        'bank_code'       => $bankCode,
        'nw'              => 'BNK',                      // CONSTANT for ALL AII
        'ts'              => gmdate('Y-m-d H:i:s'),
    ];

    $jsonBody = json_encode($payload);
    if ($jsonBody === false) {
        throw new RuntimeException('Failed to encode ANM request');
    }

    ussdLog('ANM_LOOKUP_START', [
        'phone'     => $phoneLocal,
        'bank_code' => $bankCode,
        'exttrid'   => $exttrid,
    ]);

    // 3. Sign + POST
    $startedAt = microtime(true);
    $ch = curl_init($baseUrl . '/sendRequest');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . anmSignBody($jsonBody),
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $rawResponse = curl_exec($ch);
    $latencyMs   = (int)round((microtime(true) - $startedAt) * 1000);
    $httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr     = curl_error($ch);
    $curlErrno   = curl_errno($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        ussdLog('ANM_CURL_ERROR', [
            'err'     => $curlErr,
            'errno'   => $curlErrno,
            'status'  => $httpStatus,
            'lat_ms'  => $latencyMs,
            'exttrid' => $exttrid,
        ]);
        throw new RuntimeException("Could not reach MoMo provider. Please try again.");
    }

    // 4. Parse response
    $body = json_decode((string)$rawResponse, true);
    if (!is_array($body)) {
        ussdLog('ANM_BAD_RESPONSE', [
            'status'  => $httpStatus,
            'snippet' => substr((string)$rawResponse, 0, 200),
            'exttrid' => $exttrid,
        ]);
        throw new RuntimeException("MoMo provider returned an invalid response.");
    }

    $respCode = (string)($body['resp_code'] ?? '');
    $respDesc = (string)($body['resp_desc'] ?? '');
    $name     = (string)($body['name'] ?? '');

    // AII success: resp_code "027" with non-empty name
    if ($respCode !== '027' || $name === '') {
        ussdLog('ANM_LOOKUP_FAILED', [
            'phone'     => $phoneLocal,
            'bank_code' => $bankCode,
            'resp_code' => $respCode,
            'resp_desc' => $respDesc,
            'exttrid'   => $exttrid,
        ]);
        $userMsg = $respDesc !== '' ? $respDesc : 'Unknown error';
        throw new RuntimeException($userMsg);
    }

    // 5. Cache for 30 days
    anmCacheStore($phoneLocal, $bankCode, $name, $body);

    ussdLog('ANM_LOOKUP_OK', [
        'phone'     => $phoneLocal,
        'bank_code' => $bankCode,
        'lat_ms'    => $latencyMs,
        'exttrid'   => $exttrid,
    ]);

    return $name;
}

/**
 * Read a cached successful lookup. Returns the name or null on miss/expired.
 * Best-effort — any DB error returns null (caller falls back to a live call).
 *
 * Note: nameLookupCache uses `provider` column. We store the ANM bank_code
 * there (MTN/VOD/AIR) for consistency with what we're actually looking up.
 * If the web app stores something different there, cache hits won't happen
 * but cache misses fall through to a live call — safe.
 */
function anmCacheGet(string $msisdnLocal, string $bankCode): ?string
{
    try {
        $stmt = db()->prepare(
            "SELECT returnedName FROM nameLookupCache
             WHERE msisdn = :m AND provider = :p
               AND lookupStatus = 'success' AND expiresAt > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':m' => $msisdnLocal, ':p' => $bankCode]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return (string)$row['returnedName'];
    } catch (Throwable $e) {
        ussdLog('ANM_CACHE_READ_ERROR', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Store a successful lookup. Best-effort.
 */
function anmCacheStore(string $msisdnLocal, string $bankCode, string $name, array $rawBody): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO nameLookupCache
                (msisdn, provider, returnedName, lookupStatus, rawResponse, lookedUpAt, expiresAt)
             VALUES
                (:m, :p, :name, 'success', :raw, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))"
        );
        $stmt->execute([
            ':m'    => $msisdnLocal,
            ':p'    => $bankCode,
            ':name' => $name,
            ':raw'  => json_encode($rawBody),
        ]);
    } catch (Throwable $e) {
        ussdLog('ANM_CACHE_WRITE_ERROR', ['error' => $e->getMessage()]);
    }
}

/**
 * Initiate a Customer-To-Merchant (CTM) MoMo deposit.
 *
 *   - User's MoMo wallet → BlackRed
 *   - ANM sends the user a PIN prompt on their handset
 *   - User approves, money moves, ANM calls our callback
 *
 * This is the synchronous accept/reject step. Result tells you whether ANM
 * accepted the request for processing. The actual money outcome lands later
 * via the callback at /callbacks/momo.php.
 *
 * @param  string $exttrid      Our 16-hex idempotency key (same value goes in
 *                              depositRequest.refNumber)
 * @param  string $phoneLocal   Payer's phone in 0-prefixed local form
 * @param  string $bankCode     ANM bank code: MTN | VOD | AIR
 * @param  string $amountGhs    Amount as decimal GHS string ("50.00")
 * @param  string $reference    ≤10 chars; appears in the customer's MoMo prompt
 * @param  string $callbackUrl  Absolute URL ANM will POST the outcome to
 *
 * @return array{accepted: bool, respCode: ?string, respDesc: ?string, raw: array}
 *
 * @throws RuntimeException on network/transport errors (caller catches)
 */
function anmInitiateCtm(
    string $exttrid,
    string $phoneLocal,
    string $bankCode,
    string $amountGhs,
    string $reference,
    string $callbackUrl
): array {
    global $USSD_CONFIG;
    $cfg       = $USSD_CONFIG['anm'];
    $serviceId = (string)($cfg['service_id'] ?? '70');
    $baseUrl   = rtrim((string)($cfg['base_url'] ?? ''), '/');
    $timeout   = (int)($cfg['timeout_sec'] ?? 180);

    if ($baseUrl === '') {
        throw new RuntimeException('ANM base URL not configured');
    }

    $payload = [
        'client_id'       => $serviceId,
        'customer_number' => $phoneLocal,
        'amount'          => $amountGhs,
        'exttrid'         => $exttrid,
        'reference'       => substr($reference, 0, 10),  // ANM caps at 10
        'nw'              => $bankCode,
        'trans_type'      => 'CTM',
        'callback_url'    => $callbackUrl,
        'ts'              => gmdate('Y-m-d H:i:s'),
    ];

    $jsonBody = json_encode($payload);
    if ($jsonBody === false) {
        throw new RuntimeException('Failed to encode ANM CTM request');
    }

    ussdLog('ANM_CTM_START', [
        'exttrid'   => $exttrid,
        'phone'     => $phoneLocal,
        'bank_code' => $bankCode,
        'amount'    => $amountGhs,
    ]);

    $startedAt = microtime(true);
    $ch = curl_init($baseUrl . '/sendRequest');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . anmSignBody($jsonBody),
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $rawResponse = curl_exec($ch);
    $latencyMs   = (int)round((microtime(true) - $startedAt) * 1000);
    $httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr     = curl_error($ch);
    $curlErrno   = curl_errno($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        ussdLog('ANM_CTM_CURL_ERROR', [
            'exttrid' => $exttrid,
            'err'     => $curlErr,
            'errno'   => $curlErrno,
            'status'  => $httpStatus,
            'lat_ms'  => $latencyMs,
        ]);
        throw new RuntimeException("Could not reach MoMo provider. Please try again.");
    }

    $body = json_decode((string)$rawResponse, true);
    if (!is_array($body)) {
        ussdLog('ANM_CTM_BAD_RESPONSE', [
            'exttrid' => $exttrid,
            'status'  => $httpStatus,
            'snippet' => substr((string)$rawResponse, 0, 200),
        ]);
        throw new RuntimeException("MoMo provider returned an invalid response.");
    }

    $respCode = isset($body['resp_code']) ? (string)$body['resp_code'] : null;
    $respDesc = isset($body['resp_desc']) ? (string)$body['resp_desc'] : null;

    // 015 = accepted/queued (CTM happy path), 027 = completed synchronously
    $accepted = in_array($respCode, ['015', '027'], true);

    ussdLog($accepted ? 'ANM_CTM_ACCEPTED' : 'ANM_CTM_REJECTED', [
        'exttrid'  => $exttrid,
        'respCode' => $respCode,
        'respDesc' => $respDesc,
        'lat_ms'   => $latencyMs,
    ]);

    return [
        'accepted' => $accepted,
        'respCode' => $respCode,
        'respDesc' => $respDesc,
        'raw'      => $body,
    ];
}

/**
 * Initiate a Merchant-To-Customer (MTC) MoMo payout.
 *
 *   - BlackRed → user's MoMo wallet
 *   - ANM debits our MOMO_FLOAT account (plus fee) and credits the user
 *   - ANM calls our callback when the disbursement resolves
 *
 * Payload is identical in shape to CTM — only trans_type differs.
 *
 * @param  string $exttrid      Our 16-hex idempotency key (matches withdrawalRequest.refNumber)
 * @param  string $phoneLocal   Recipient's phone in 0-prefixed form
 * @param  string $bankCode     ANM bank code: MTN | VOD | AIR
 * @param  string $amountGhs    Amount as decimal GHS string ("50.00")
 * @param  string $reference    ≤10 chars; appears in the recipient's MoMo notification
 * @param  string $callbackUrl  Absolute URL ANM will POST the outcome to
 *
 * @return array{accepted: bool, respCode: ?string, respDesc: ?string, raw: array}
 *
 * @throws RuntimeException on network/transport errors
 */
function anmInitiateMtc(
    string $exttrid,
    string $phoneLocal,
    string $bankCode,
    string $amountGhs,
    string $reference,
    string $callbackUrl
): array {
    global $USSD_CONFIG;
    $cfg       = $USSD_CONFIG['anm'];
    $serviceId = (string)($cfg['service_id'] ?? '70');
    $baseUrl   = rtrim((string)($cfg['base_url'] ?? ''), '/');
    $timeout   = (int)($cfg['timeout_sec'] ?? 180);

    if ($baseUrl === '') {
        throw new RuntimeException('ANM base URL not configured');
    }

    $payload = [
        'client_id'       => $serviceId,
        'customer_number' => $phoneLocal,
        'amount'          => $amountGhs,
        'exttrid'         => $exttrid,
        'reference'       => substr($reference, 0, 10),
        'nw'              => $bankCode,
        'trans_type'      => 'MTC',
        'callback_url'    => $callbackUrl,
        'ts'              => gmdate('Y-m-d H:i:s'),
    ];

    $jsonBody = json_encode($payload);
    if ($jsonBody === false) {
        throw new RuntimeException('Failed to encode ANM MTC request');
    }

    ussdLog('ANM_MTC_START', [
        'exttrid'   => $exttrid,
        'phone'     => $phoneLocal,
        'bank_code' => $bankCode,
        'amount'    => $amountGhs,
    ]);

    $startedAt = microtime(true);
    $ch = curl_init($baseUrl . '/sendRequest');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . anmSignBody($jsonBody),
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $rawResponse = curl_exec($ch);
    $latencyMs   = (int)round((microtime(true) - $startedAt) * 1000);
    $httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr     = curl_error($ch);
    $curlErrno   = curl_errno($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        ussdLog('ANM_MTC_CURL_ERROR', [
            'exttrid' => $exttrid,
            'err'     => $curlErr,
            'errno'   => $curlErrno,
            'status'  => $httpStatus,
            'lat_ms'  => $latencyMs,
        ]);
        throw new RuntimeException("Could not reach MoMo provider. Please try again.");
    }

    $body = json_decode((string)$rawResponse, true);
    if (!is_array($body)) {
        ussdLog('ANM_MTC_BAD_RESPONSE', [
            'exttrid' => $exttrid,
            'status'  => $httpStatus,
            'snippet' => substr((string)$rawResponse, 0, 200),
        ]);
        throw new RuntimeException("MoMo provider returned an invalid response.");
    }

    $respCode = isset($body['resp_code']) ? (string)$body['resp_code'] : null;
    $respDesc = isset($body['resp_desc']) ? (string)$body['resp_desc'] : null;

    // 015 = accepted/queued (MTC happy path), 027 = completed synchronously
    $accepted = in_array($respCode, ['015', '027'], true);

    ussdLog($accepted ? 'ANM_MTC_ACCEPTED' : 'ANM_MTC_REJECTED', [
        'exttrid'  => $exttrid,
        'respCode' => $respCode,
        'respDesc' => $respDesc,
        'lat_ms'   => $latencyMs,
    ]);

    return [
        'accepted' => $accepted,
        'respCode' => $respCode,
        'respDesc' => $respDesc,
        'raw'      => $body,
    ];
}
