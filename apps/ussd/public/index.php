<?php

declare(strict_types=1);

// Channel adapter entry point (Story 8.1). Every money and game operation goes
// through PlatformClient over HTTP to /v1 — this file itself holds no ledger writes
// and no database access (project-context.md rule 19-20).
//
// Wire format assumed: Africa's Talking-style form POST — sessionId, phoneNumber,
// text, serviceCode. No aggregator is contracted yet; adjust this parsing block for
// whichever one is (see MenuEngine's doc comment on the same open point).

require __DIR__ . '/../vendor/autoload.php';

use Betplus\Ussd\Config;
use Betplus\Ussd\Http\HttpClient;
use Betplus\Ussd\MenuEngine;
use Betplus\Ussd\PlatformClient;
use Betplus\Ussd\Session\FileSessionStore;

$sessionId = (string) ($_POST['sessionId'] ?? '');
$phoneNumber = (string) ($_POST['phoneNumber'] ?? '');
$text = (string) ($_POST['text'] ?? '');

header('Content-Type: text/plain');

if ($sessionId === '' || $phoneNumber === '') {
    http_response_code(400);
    echo 'END Invalid request.';
    exit;
}

$platform = new PlatformClient(Config::platformBaseUrl(), Config::gatewaySharedSecret(), new HttpClient(Config::requestTimeoutSeconds()));
$sessions = new FileSessionStore(Config::sessionStoreDir());
$engine = new MenuEngine($platform, $sessions);

echo $engine->handleTurn($sessionId, $phoneNumber, $text)->render();
