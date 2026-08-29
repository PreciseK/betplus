<?php
/**
 * Nalo response helpers.
 *
 * Nalo expects JSON: {USERID, MSISDN, MSGTYPE, MSG}.
 *   MSGTYPE true  = expect more input (CON semantics)
 *   MSGTYPE false = end session (END semantics)
 *
 * Three constructors used by states:
 *   respStay($msg)         — re-render current state (validation error / retry)
 *   respNext($nextState)   — transition; index.php will re-render the new state
 *   respEnd($msg)          — terminal screen, session ends
 *
 * Each returns a plain array. index.php is responsible for sending the JSON
 * to the wire.
 *
 * The "next state" form has an empty message because the next state's
 * render() will produce the actual screen text.
 *
 * POST-RESPONSE TASKS:
 *   Some flows (notably deposits) need to do work AFTER the USSD session
 *   has fully closed on the handset. ANM/MoMo can't reliably push a PIN
 *   prompt while our USSD session is still active, so we must close the
 *   session first, wait ~3 seconds, then fire the ANM call.
 *
 *   A state can request this by adding `postResponseTask` to its END
 *   response. Use respEndWithTask() for that. index.php picks it up,
 *   sends the response, finishes the request, then runs the task.
 *
 *   Currently the only task is 'deposit_anm_call' (see lib/deposit.php
 *   for the runner). Add more as needed.
 */

function respStay(string $msg): array
{
    return ['continue' => true, 'message' => $msg, 'nextState' => null];
}

function respNext(string $nextState): array
{
    return ['continue' => true, 'message' => '', 'nextState' => $nextState];
}

function respEnd(string $msg): array
{
    return ['continue' => false, 'message' => $msg, 'nextState' => null];
}

/**
 * END the session AND queue a task to run after the response is sent.
 *
 *   $task — short identifier index.php knows how to dispatch
 *   $args — arbitrary data to pass to the task runner
 */
function respEndWithTask(string $msg, string $task, array $args = []): array
{
    return [
        'continue'         => false,
        'message'          => $msg,
        'nextState'        => null,
        'postResponseTask' => $task,
        'taskArgs'         => $args,
    ];
}

/**
 * Serialize an internal response to the JSON shape Nalo expects.
 */
function toNaloPayload(array $resp, string $userId, string $msisdn): array
{
    return [
        'USERID'  => $userId,
        'MSISDN'  => $msisdn,
        'MSGTYPE' => (bool) $resp['continue'],
        'MSG'     => (string) $resp['message'],
    ];
}

/**
 * Send the response to Nalo and close the HTTP connection, but keep this
 * PHP process running so we can do post-response work (like a delayed
 * ANM call).
 *
 * Tries the LiteSpeed and PHP-FPM native functions first. Falls back to
 * Connection: close + manual flush for plain Apache mod_php.
 *
 * Note on logging: we DO want to keep logging from the post-response code
 * since users won't see those errors. The error log + ussd.log capture them.
 */
function respondAndContinue(string $jsonBody): void
{
    // Set the JSON header BEFORE we flush so it gets sent.
    header('Content-Type: application/json');

    // LiteSpeed native — what's on our server
    if (function_exists('litespeed_finish_request')) {
        echo $jsonBody;
        litespeed_finish_request();
        return;
    }

    // PHP-FPM native
    if (function_exists('fastcgi_finish_request')) {
        echo $jsonBody;
        fastcgi_finish_request();
        return;
    }

    // Fallback for everything else (Apache mod_php, dev built-in server)
    // We tell the client connection-close + content-length, flush all
    // buffers, then continue.
    ignore_user_abort(true);
    if (ob_get_level() === 0) {
        ob_start();
    }
    echo $jsonBody;
    $size = ob_get_length();
    header('Connection: close');
    header('Content-Length: ' . $size);
    // Flush all output buffer levels
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
}
