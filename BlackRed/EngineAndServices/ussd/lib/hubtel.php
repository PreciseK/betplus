<?php
/**
 * Hubtel SMS client — USSD slim version.
 *
 * Sends transactional SMS via Hubtel's Programmable SMS API.
 *
 *   POST https://smsc.hubtel.com/v1/messages/send
 *   Authorization: Basic <base64(client_id:client_secret)>
 *   Content-Type: application/json
 *   Body: { From, To, Content }
 *
 * Why Basic-Auth POST and not the URL-auth GET that appears in some samples:
 *   The GET form puts client_id and client_secret in the query string. That
 *   string ends up in Hubtel's HTTP access logs, in any proxy/CDN logs, and
 *   risks getting captured in our own debug logs. Basic Auth puts them in the
 *   Authorization header — TLS-protected, never URL-logged.
 *
 * Failure mode: this function is best-effort. Sending SMS failure must NEVER
 * block a USSD flow or roll back a registration. We log every outcome to
 * ussd.log; the caller doesn't get an exception unless network plumbing
 * itself broke. The SMS log gives ops a way to detect delivery problems
 * without affecting users.
 *
 * Logging hygiene: we log the recipient, sender, content length, and
 * outcome. We never log the credentials, full Authorization header, or
 * URL with query string. The Hubtel API has no GET-of-secrets path; we
 * don't accidentally introduce one here.
 */

/**
 * Send a transactional SMS via Hubtel.
 *
 * @param  string $toMsisdn   Recipient — canonical 233XXXXXXXXX or local 0XXXXXXXXX,
 *                            we normalise to the form Hubtel expects.
 * @param  string $content    Message body. Truncated to 459 chars (3 SMS) defensively.
 * @return bool               true on accepted (Hubtel returned a delivery status ID),
 *                            false on any failure (logged, no exception).
 */
function hubtelSendSms(string $toMsisdn, string $content): bool
{
    global $USSD_CONFIG;
    $cfg = $USSD_CONFIG['hubtel'] ?? [];

    $clientId     = (string)($cfg['client_id'] ?? '');
    $clientSecret = (string)($cfg['client_secret'] ?? '');
    $senderId     = (string)($cfg['sender_id'] ?? 'BlackRed');
    $baseUrl      = rtrim((string)($cfg['base_url'] ?? 'https://smsc.hubtel.com/v1/messages/send'), '/');
    $timeout      = (int)($cfg['timeout_sec'] ?? 15);

    if ($clientId === '' || $clientSecret === '') {
        ussdLog('HUBTEL_CONFIG_MISSING', ['has_id' => $clientId !== '', 'has_secret' => $clientSecret !== '']);
        return false;
    }

    // Hubtel accepts both 233XXX and 0XXX. We send canonical 233XXX without
    // the plus (matches the format they show in samples).
    $to = preg_replace('/[\s\-\(\)\.\+]/', '', $toMsisdn) ?? '';
    if (str_starts_with($to, '0') && strlen($to) === 10) {
        $to = '233' . substr($to, 1);
    }
    if (strlen($to) !== 12 || !str_starts_with($to, '233')) {
        ussdLog('HUBTEL_BAD_MSISDN', ['given' => $toMsisdn, 'normalised' => $to]);
        return false;
    }

    // Defensive truncation. Hubtel supports concat SMS but we never want to
    // accidentally send a 50-segment message because of a bug upstream.
    if (strlen($content) > 459) {
        $content = substr($content, 0, 456) . '...';
    }

    $payload = [
        'From'    => $senderId,
        'To'      => $to,
        'Content' => $content,
    ];
    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($jsonBody === false) {
        ussdLog('HUBTEL_ENCODE_FAILED', []);
        return false;
    }

    $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

    $startedAt = microtime(true);
    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $authHeader,
            'Content-Type: application/json',
            'Accept: application/json',
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

    // Note: we deliberately log the recipient + content length only. NO body,
    // NO auth header, NO URL with query string. The Authorization header
    // would already have been in the request (TLS-protected) but never makes
    // it into our log.
    $logCtx = [
        'to'         => $to,
        'from'       => $senderId,
        'len'        => strlen($content),
        'status'     => $httpStatus,
        'lat_ms'     => $latencyMs,
    ];

    if ($rawResponse === false) {
        $logCtx['err']   = $curlErr;
        $logCtx['errno'] = $curlErrno;
        ussdLog('HUBTEL_CURL_ERROR', $logCtx);
        return false;
    }

    // Hubtel returns 201 Created with JSON body containing MessageId and Status.
    // Anything 2xx we treat as accepted. 4xx/5xx are failures.
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $logCtx['snippet'] = substr((string)$rawResponse, 0, 200);
        ussdLog('HUBTEL_HTTP_ERROR', $logCtx);
        return false;
    }

    $body = json_decode((string)$rawResponse, true);
    $logCtx['messageId'] = is_array($body) ? ($body['MessageId'] ?? null) : null;
    ussdLog('HUBTEL_SENT', $logCtx);
    return true;
}

/**
 * Send the welcome SMS after USSD registration.
 *
 * Template (per BR-USSD-001):
 *   "Hello [Name], Welcome to BlackRed! Your account has been registered
 *    successfully. Place your stakes now and stand a chance to win big!"
 *
 * @param  string $canonicalMsisdn  Player's canonical phone (233XXX)
 * @param  string $firstName        Player's first name, title-cased
 * @return bool                     Send outcome (best-effort)
 */
function hubtelSendWelcomeSms(string $canonicalMsisdn, string $firstName): bool
{
    $msg = "Hello $firstName, Welcome to BlackRed! "
         . "Your account has been registered successfully. "
         . "Place your stakes now and stand a chance to win big!";
    return hubtelSendSms($canonicalMsisdn, $msg);
}
