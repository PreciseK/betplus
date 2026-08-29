<?php
/**
 * BlackRed USSD — Nalo webhook entrypoint.
 *
 * This is the ONLY web-accessible PHP file. All requests from Nalo come here.
 *
 * Flow per turn:
 *   1. Parse incoming JSON (Nalo's payload)
 *   2. Normalize MSISDN
 *   3. Log inbound
 *   4. If first turn (USERDATA matches a configured trigger):
 *        - create new ussdSession row at INITIAL_STATE
 *        - render that state, return response
 *   5. Otherwise:
 *        - load existing session FOR UPDATE inside a txn
 *        - call current state's handle(input)
 *        - if handle returned a transition, render new state
 *        - save session
 *        - return response
 *   6. Log outbound
 *   7. echo JSON to Nalo
 *
 * Errors are caught and converted to a graceful END response — Nalo never
 * sees a 500 from us.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config_missing', 'msg' => 'Copy config.example.php to config.php']);
    exit;
}

// Make the loaded config accessible to lib/*.php (which use `global $USSD_CONFIG`).
$USSD_CONFIG = require $configPath;
if (!is_array($USSD_CONFIG)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config_invalid']);
    exit;
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/msisdn.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/player.php';
require_once __DIR__ . '/lib/password.php';
require_once __DIR__ . '/lib/anm.php';
require_once __DIR__ . '/lib/hubtel.php';
require_once __DIR__ . '/lib/deposit.php';
require_once __DIR__ . '/lib/withdrawal.php';
require_once __DIR__ . '/lib/game.php';
require_once __DIR__ . '/lib/states.php';

// -----------------------------------------------------------------------------
// Read Nalo's POST body
// -----------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '';
ussdLog('INBOUND', ['body' => $rawBody]);

$incoming = json_decode($rawBody, true);
if (!is_array($incoming)) {
    ussdLog('PARSE_ERROR', ['raw' => $rawBody]);
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

// -----------------------------------------------------------------------------
// Extract Nalo fields
// -----------------------------------------------------------------------------
$sessionId = isset($incoming['SESSIONID']) ? trim((string) $incoming['SESSIONID']) : '';
$userId    = isset($incoming['USERID'])    ? trim((string) $incoming['USERID'])    : '';
$rawInput  = isset($incoming['USERDATA'])  ? trim((string) $incoming['USERDATA'])  : '';
$rawMsisdn = isset($incoming['MSISDN'])    ? trim((string) $incoming['MSISDN'])    : '';
$network   = isset($incoming['NETWORK'])   ? trim((string) $incoming['NETWORK'])   : null;

if ($sessionId === '' || $rawMsisdn === '') {
    ussdLog('MISSING_FIELDS', ['have' => array_keys($incoming)]);
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

try {
    $msisdn = normalizeMsisdn($rawMsisdn);
} catch (InvalidArgumentException $e) {
    ussdLog('BAD_MSISDN', ['raw' => $rawMsisdn]);
    http_response_code(400);
    echo json_encode(['error' => 'bad_msisdn']);
    exit;
}

// -----------------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------------
$triggers = $USSD_CONFIG['ussd']['triggers'] ?? ['*920*9'];

try {
    // First turn? Nalo sends the dialed shortcode as USERDATA.
    if (in_array($rawInput, $triggers, true)) {
        $response = dbTxn(function () use ($sessionId, $msisdn, $network) {
            $session = sessionCreate($sessionId, $msisdn, $network, USSD_INITIAL_STATE);
            $resp = renderState($session['state'], $session);

            // Initial state may immediately transition (e.g. EntryState in PR 2
            // routes to MAIN_MENU for registered users).
            if ($resp['continue'] && $resp['nextState'] !== null) {
                sessionTransition($session, $resp['nextState']);
                $resp = renderState($session['state'], $session);
            }

            sessionSave($session);
            return $resp;
        });
    } else {
        // Subsequent turn — load session inside a txn
        $response = dbTxn(function () use ($sessionId, $rawInput) {
            $session = sessionFindForUpdate($sessionId);
            if ($session === null) {
                return respEnd("Session expired. Please dial *920*9# again.");
            }

            $resp = handleState($session['state'], $rawInput, $session);

            // If handler requested a transition, run the new state's render.
            if ($resp['continue'] && $resp['nextState'] !== null) {
                sessionTransition($session, $resp['nextState']);
                $resp = renderState($session['state'], $session);
            }

            sessionSave($session);
            return $resp;
        });
    }
} catch (Throwable $e) {
    ussdLog('DISPATCH_ERROR', [
        'sessionId' => $sessionId,
        'error'     => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ]);
    // In development, surface the error so smoke testing is easier.
    if (($USSD_CONFIG['env'] ?? 'production') === 'development') {
        $response = respEnd("ERR: " . $e->getMessage());
    } else {
        $response = respEnd("Service temporarily unavailable. Please try again shortly.");
    }
}

// -----------------------------------------------------------------------------
// Send response to Nalo
// -----------------------------------------------------------------------------
$payload = toNaloPayload($response, $userId, $msisdn);
$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

ussdLog('OUTBOUND', ['body' => $body]);

// If the state queued a post-response task, send the body via the
// connection-closing helper so we can continue executing after Nalo has
// received its response. Otherwise just echo normally.
$task = $response['postResponseTask'] ?? null;
$taskArgs = $response['taskArgs'] ?? [];

if ($task !== null) {
    respondAndContinue($body);
    runPostResponseTask($task, $taskArgs);
} else {
    echo $body;
}

/**
 * Dispatch a post-response task. Runs AFTER the HTTP response has been sent
 * to Nalo and the connection has been closed on the client side. The user's
 * USSD session is closed on their handset by this point.
 *
 * Errors are caught and logged; the user has already seen their END screen
 * and we can't show them anything else from here.
 */
function runPostResponseTask(string $task, array $args): void
{
    try {
        ussdLog('POST_TASK_START', ['task' => $task, 'args' => $args]);

        switch ($task) {
            case 'deposit_anm_call':
                // ANM/MoMo requires the USSD session to be fully closed
                // before the PIN prompt is fired. 3s is empirically what
                // ANM recommends.
                sleep(3);
                $depositId = (int)($args['depositId'] ?? 0);
                if ($depositId > 0) {
                    depositFireAnm($depositId);
                }
                break;

            case 'withdrawal_anm_call':
                // Same USSD-close timing as deposit. ANM MTC fires the
                // disbursement after the user's session is gone.
                sleep(3);
                $withdrawalId = (int)($args['withdrawalId'] ?? 0);
                if ($withdrawalId > 0) {
                    withdrawalToMomoFireAnm($withdrawalId);
                }
                break;

            default:
                ussdLog('POST_TASK_UNKNOWN', ['task' => $task]);
        }

        ussdLog('POST_TASK_DONE', ['task' => $task]);

    } catch (Throwable $e) {
        ussdLog('POST_TASK_ERROR', [
            'task'  => $task,
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);
    }
}
