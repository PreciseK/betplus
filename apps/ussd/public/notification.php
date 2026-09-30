<?php

declare(strict_types=1);

// Africa's Talking USSD Event Notifications Callback
// Receives end-of-session data: sessionId, serviceCode, networkCode, phoneNumber,
// status (Success | Incomplete | Failed), cost, durationInMillis, hopsCount, etc.

require __DIR__ . '/../vendor/autoload.php';

use Betplus\Ussd\Config;
use Betplus\Ussd\Http\HttpClient;
use Betplus\Ussd\PlatformClient;

header('Content-Type: text/plain');

// Verify caller is Africa's Talking.
//
// IP check: uses REMOTE_ADDR only — X-Forwarded-For is NOT read here because it
// can be spoofed by the caller. Deployments behind a reverse proxy must configure
// the proxy to rewrite REMOTE_ADDR to the client IP (standard nginx/Apache behaviour)
// rather than rely on XFF headers from untrusted sources.
//
// Secret check: AT_NOTIFICATION_SECRET is required (fail-closed). Configure Africa's
// Talking to send it as the X-AT-Secret header, and set the matching env var here.
Config::loadEnv();

$notificationSecret = getenv('AT_NOTIFICATION_SECRET');
if ($notificationSecret === false || $notificationSecret === '') {
    http_response_code(500);
    error_log('[USSD] AT_NOTIFICATION_SECRET is not configured — notification endpoint is refusing all requests until it is set.');
    echo 'Service misconfigured';
    exit;
}

$providedSecret = (string) ($_SERVER['HTTP_X_AT_SECRET'] ?? '');
if (!hash_equals($notificationSecret, $providedSecret)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$callerIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipAllowlistRaw = getenv('AT_NOTIFICATION_IP_ALLOWLIST');
if ($ipAllowlistRaw !== false && $ipAllowlistRaw !== '') {
    $allowedIps = array_filter(array_map('trim', explode(',', $ipAllowlistRaw)));
    if (!in_array($callerIp, $allowedIps, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$sessionId = (string) ($_POST['sessionId'] ?? '');
$status = (string) ($_POST['status'] ?? 'ended');
$phoneNumber = (string) ($_POST['phoneNumber'] ?? '');
$input = (string) ($_POST['input'] ?? '');

if ($sessionId !== '') {
    try {
        $platform = new PlatformClient(
            Config::platformBaseUrl(),
            Config::gatewaySharedSecret(),
            new HttpClient(Config::requestTimeoutSeconds())
        );
        // Sync final status to platform session audit log
        $platform->syncSession($sessionId, $phoneNumber, 'session_end', $input, strtolower($status));
    } catch (\Throwable $e) {
        // Log or silently accept so AT receives 200 OK
        error_log('USSD notification sync error: ' . $e->getMessage());
    }
}

// Africa's Talking expects HTTP 200 OK
http_response_code(200);
echo 'OK';
