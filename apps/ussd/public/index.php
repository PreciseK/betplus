<?php

declare(strict_types=1);

// Channel adapter entry point (Story 8.1). Every money and game operation goes
// through PlatformClient over HTTP to /v1 — this file itself holds no ledger writes
// and no database access (project-context.md rule 19-20).
//
// Wire format assumed: Africa's Talking-style form POST — sessionId, phoneNumber,
// text, serviceCode. No aggregator is contracted yet; adjust this parsing block for
// whichever one is (see MenuEngine's doc comment on the same open point).

// Return graceful 200 OK for GET requests (health checks / browser visits)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/plain');
    echo 'END Betplus USSD Gateway Active';
    exit;
}

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    header('Content-Type: text/plain');
    echo 'END Service temporarily unavailable. Please try again shortly.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Betplus\Ussd\Config;
use Betplus\Ussd\Http\HttpClient;
use Betplus\Ussd\MenuEngine;
use Betplus\Ussd\PlatformClient;
use Betplus\Ussd\Session\FileSessionStore;

header('Content-Type: text/plain');

// Security Check 1: Aggregator IP allowlist (when configured)
$allowedIps = Config::aggregatorIpAllowlist();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "END Access forbidden.";
    exit;
}

// Security Check 2: Aggregator webhook authentication / signature (when configured)
$aggregatorSecret = Config::aggregatorSecret();
if ($aggregatorSecret !== null) {
    $headerSig = (string) ($_SERVER['HTTP_X_AGGREGATOR_SIGNATURE'] ?? $_SERVER['HTTP_X_USSD_SIGNATURE'] ?? '');
    $apiKey = (string) ($_POST['apiKey'] ?? $_POST['token'] ?? '');
    $rawInput = file_get_contents('php://input');
    $expectedHmac = hash_hmac('sha256', (string) $rawInput, $aggregatorSecret);

    $validSig = ($headerSig !== '' && hash_equals($expectedHmac, $headerSig));
    $validToken = ($apiKey !== '' && hash_equals($aggregatorSecret, $apiKey));

    if (!$validSig && !$validToken) {
        http_response_code(401);
        echo "END Unauthorized gateway request.";
        exit;
    }
}

$sessionId = (string) ($_POST['sessionId'] ?? '');
$phoneNumber = (string) ($_POST['phoneNumber'] ?? '');
$text = (string) ($_POST['text'] ?? '');

if ($sessionId === '' && $phoneNumber === '') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $data = json_decode($rawInput, true);
        if (is_array($data)) {
            $sessionId = (string) ($data['sessionId'] ?? $data['session_id'] ?? '');
            $phoneNumber = (string) ($data['phoneNumber'] ?? $data['phone_number'] ?? $data['msisdn'] ?? '');
            $text = (string) ($data['text'] ?? $data['message'] ?? '');
        }
    }
}

if ($sessionId === '' || $phoneNumber === '') {
    echo "END Welcome to Betplus.\nDial with a valid USSD session.";
    exit;
}

try {
    $platform = new PlatformClient(
        Config::platformBaseUrl(),
        Config::gatewaySharedSecret(),
        new HttpClient(Config::requestTimeoutSeconds())
    );
    $sessions = new FileSessionStore(Config::sessionStoreDir(), Config::sessionEncryptionKey());
    $engine = new MenuEngine($platform, $sessions);

    echo $engine->handleTurn($sessionId, $phoneNumber, $text)->render();
} catch (\Throwable $e) {
    error_log('[USSD Error] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo 'END We are unable to process your request at the moment. Please try again shortly.';
}
