<?php
/**
 * ANM callback endpoint.
 *
 * Public, called server-to-server by ANM Orchard after a MoMo transaction
 * settles. ANM POSTs:
 *   {
 *     "trans_ref":    "<our refNumber>",
 *     "trans_status": "000/200"   ← "000" prefix = success, else failure
 *   }
 *
 * For USSD deposits we:
 *   1. Look up the depositRequest by refNumber
 *   2. If channel != 'ussd' → no-op (web app's CallbackController handles it)
 *   3. If success: atomic credit pipeline + confirmation SMS
 *   4. If failure: mark row failed + apology SMS
 *   5. Return 200 OK so ANM doesn't retry
 *
 * Idempotency:
 *   ANM may retry callbacks. depositSettleSuccess/Failure are idempotent —
 *   second call sees status='succeeded' or 'failed' and returns true without
 *   doing work or sending duplicate SMS. The walletTransaction.refNumber
 *   UNIQUE constraint is a backstop.
 *
 * Security:
 *   No auth on the URL itself in PR 5 — ANM doesn't sign callbacks.
 *   Defense relies on refNumber being unguessable (16 random hex from
 *   deposit creation = 64 bits of entropy) and on us only processing rows
 *   that we created with the matching refNumber. Hardening (PR 7) will
 *   add an IP allowlist for the ANM source IP.
 *
 * Always returns 200 — even on errors. The user has already moved on, and
 * a 200 stops ANM's retry storm. Internal errors are logged for ops.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config_missing']);
    exit;
}

$USSD_CONFIG = require $configPath;

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/msisdn.php';
require_once __DIR__ . '/../lib/player.php';
require_once __DIR__ . '/../lib/hubtel.php';
require_once __DIR__ . '/../lib/anm.php';
require_once __DIR__ . '/../lib/deposit.php';

// -----------------------------------------------------------------------------
// Read + parse
// -----------------------------------------------------------------------------
$rawBody  = file_get_contents('php://input') ?: '';
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

ussdLog('ANM_CALLBACK_INBOUND', [
    'ip'   => $clientIp,
    'body' => $rawBody,
]);

$incoming = json_decode($rawBody, true);
if (!is_array($incoming)) {
    ussdLog('ANM_CALLBACK_BAD_JSON', ['ip' => $clientIp]);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$transRef    = isset($incoming['trans_ref'])    ? trim((string) $incoming['trans_ref'])    : '';
$transStatus = isset($incoming['trans_status']) ? trim((string) $incoming['trans_status']) : '';

if ($transRef === '' || $transStatus === '') {
    ussdLog('ANM_CALLBACK_BAD_BODY', [
        'ip'   => $clientIp,
        'keys' => array_keys($incoming),
    ]);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

// -----------------------------------------------------------------------------
// Look up the deposit (or withdrawal — PR 6 will branch here)
// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
// Look up deposit OR withdrawal
//
// ANM's callback shape is the same for both directions (just trans_ref +
// trans_status). We figure out which kind of operation it is by looking the
// refNumber up in both tables — whichever finds a row wins.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../lib/withdrawal.php';

try {
    $deposit = depositByRef($transRef);
} catch (Throwable $e) {
    ussdLog('ANM_CALLBACK_LOOKUP_DEPOSIT_ERROR', [
        'ref'   => $transRef,
        'error' => $e->getMessage(),
    ]);
    $deposit = null;
}

try {
    $withdrawal = $deposit === null ? withdrawalByRef($transRef) : null;
} catch (Throwable $e) {
    ussdLog('ANM_CALLBACK_LOOKUP_WITHDRAWAL_ERROR', [
        'ref'   => $transRef,
        'error' => $e->getMessage(),
    ]);
    $withdrawal = null;
}

if ($deposit === null && $withdrawal === null) {
    // Unknown reference — could be a web-initiated transaction (web app
    // has its own callback handler), or completely unknown. Either way
    // log and ack 200 so ANM stops retrying.
    ussdLog('ANM_CALLBACK_UNKNOWN_REF', [
        'ref' => $transRef,
        'ip'  => $clientIp,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// Both apps share a callback URL in principle, but each app should only
// handle ITS OWN channel. Web-initiated transactions land in the web app's
// callback handler; USSD-initiated land here.
$record = $deposit ?? $withdrawal;
if ($record['channel'] !== 'ussd') {
    ussdLog('ANM_CALLBACK_NOT_USSD', [
        'ref'     => $transRef,
        'channel' => $record['channel'],
        'type'    => $deposit !== null ? 'deposit' : 'withdrawal',
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// Settle — parse status, dispatch to the right handler
//
// ANM convention: "000/200" → first segment is the auth/business outcome.
//   "000" = success, anything else = failure.
// -----------------------------------------------------------------------------
$parts = explode('/', $transStatus);
$primary = $parts[0] ?? '';
$isSuccess = $primary === '000';

try {
    if ($deposit !== null) {
        // ---- Deposit (CTM) callback ----
        if ($isSuccess) {
            $handled = depositSettleSuccess($transRef, $transStatus, $clientIp, $incoming);
            if ($handled) {
                $post = depositByRef($transRef);
                if ($post !== null && $post['status'] === 'succeeded') {
                    try {
                        $bal = playerBalances($post['playerId']);
                        $msg = sprintf(
                            "BlackRed: %s received. New Play balance: %s. Ref: %s",
                            formatPesewas($post['amountPesewas']),
                            formatPesewas($bal['play']),
                            substr($transRef, 0, 12)
                        );
                        hubtelSendSms($post['msisdn'], $msg);
                    } catch (Throwable $e) {
                        ussdLog('ANM_CB_SMS_ERROR', [
                            'ref'   => $transRef,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } else {
            $handled = depositSettleFailure($transRef, $transStatus, $clientIp, $incoming);
            if ($handled) {
                try {
                    hubtelSendSms($deposit['msisdn'], sprintf(
                        "BlackRed: Your %s deposit could not be completed. Please try again.",
                        formatPesewas($deposit['amountPesewas'])
                    ));
                } catch (Throwable $e) {
                    ussdLog('ANM_CB_SMS_ERROR', [
                        'ref'   => $transRef,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    } else {
        // ---- Withdrawal (MTC) callback ----
        if ($isSuccess) {
            $handled = withdrawalSettleSuccess($transRef, $transStatus, $clientIp, $incoming);
            if ($handled) {
                $post = withdrawalByRef($transRef);
                if ($post !== null && $post['status'] === 'succeeded') {
                    try {
                        $bal = playerBalances($post['playerId']);
                        $msg = sprintf(
                            "BlackRed: %s sent to your MoMo. New Payout balance: %s. Ref: %s",
                            formatPesewas($post['amountPesewas']),
                            formatPesewas($bal['payout']),
                            substr($transRef, 0, 12)
                        );
                        hubtelSendSms($post['msisdn'], $msg);
                    } catch (Throwable $e) {
                        ussdLog('ANM_CB_SMS_ERROR', [
                            'ref'   => $transRef,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } else {
            $handled = withdrawalSettleFailure($transRef, $transStatus, $clientIp, $incoming);
            if ($handled) {
                try {
                    hubtelSendSms($withdrawal['msisdn'], sprintf(
                        "BlackRed: Your %s withdrawal could not be sent. Please try again.",
                        formatPesewas($withdrawal['amountPesewas'])
                    ));
                } catch (Throwable $e) {
                    ussdLog('ANM_CB_SMS_ERROR', [
                        'ref'   => $transRef,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
} catch (Throwable $e) {
    ussdLog('ANM_CALLBACK_SETTLE_ERROR', [
        'ref'   => $transRef,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
}

echo json_encode(['ok' => true]);
