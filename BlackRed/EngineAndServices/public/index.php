<?php
/**
 * BlackRed Raffle - Front Controller
 *
 * This is the ONLY PHP file directly web-accessible.
 * Every API request enters here, gets routed, processed, and a JSON response returned.
 *
 * Hardening notes:
 *  - All paths are absolute (defended against include path attacks).
 *  - Errors are NEVER displayed to the user; only logged.
 *  - Output buffering is on so errors during response generation don't leak.
 *  - The script exits cleanly via App::run() — no stray output.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Disable error display before anything else can fail. Errors go to logs only.
// -----------------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// Define paths from this script's location. Resilient to symlinks.
// -----------------------------------------------------------------------------
define('BR_PUBLIC_DIR', __DIR__);
define('BR_BASE_DIR', dirname(__DIR__));
define('BR_SRC_DIR', BR_BASE_DIR . '/src');
define('BR_CONFIG_DIR', BR_BASE_DIR . '/config');
define('BR_LOGS_DIR', BR_BASE_DIR . '/logs');
define('BR_VENDOR_DIR', BR_BASE_DIR . '/vendor');

// -----------------------------------------------------------------------------
// Composer autoloader. If missing, fail fast with a clean message.
// -----------------------------------------------------------------------------
$autoloader = BR_VENDOR_DIR . '/autoload.php';
if (!is_file($autoloader)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'server_misconfigured',
        'message' => 'Run `composer install` before serving requests.',
    ]);
    exit;
}
require $autoloader;

// -----------------------------------------------------------------------------
// Hand off to the application bootstrap. Every meaningful operation —
// loading env, building the container, routing, middleware — happens inside.
// -----------------------------------------------------------------------------
try {
    $app = new \BlackRed\Bootstrap\App(BR_BASE_DIR);
    $app->run();
} catch (\Throwable $e) {
    // Last-resort error handler. The middleware ErrorHandler should have caught
    // this; if it didn't, the bootstrap itself failed. Log to PHP error log
    // (no logger available) and return a generic 500.
    error_log(sprintf(
        '[blackred bootstrap fatal] %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'internal_server_error',
        'message' => 'The server encountered an unexpected condition.',
    ], JSON_UNESCAPED_SLASHES);
    exit(1);
}
