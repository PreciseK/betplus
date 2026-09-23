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
