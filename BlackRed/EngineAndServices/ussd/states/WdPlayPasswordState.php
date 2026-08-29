<?php
/**
 * WdMomoPasswordState
 *
 *   Enter your password
 *   to confirm:
 *
 * Verifies password, runs preflight (validates + inserts row), ENDs the
 * USSD session, queues a post-response task to fire the ANM MTC call
 * after a 3-second wait.
 *
 * Same one-retry policy as the Play withdrawal state. Same hash-check
 * via password_verify (constant time).
 *
 * After a successful password + preflight:
 *   - USSD shows "Withdrawal is on the way"
 *   - 3s later, ANM MTC fires
 *   - Either:
 *     a. ANM accepts → row pending → callback later → settle + SMS
 *     b. ANM rejects → row failed → apology SMS
 *
 * The user gets an SMS in all completion cases (success + ANM rejection
 * + callback failure), so they never wonder what happened.
 */

function render_WD_MOMO_PASSWORD(array &$session): array
{
    return respStay(
        "Enter your PIN\n"
        . "to confirm:"
    );
}

function handle_WD_MOMO_PASSWORD(string $input, array &$session): array
{
    if ($session['playerId'] === null) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $amountPesewas = (int)($session['data']['wdAmountPesewas'] ?? 0);
    if ($amountPesewas <= 0) {
        return respEnd("Session error. Please dial *920*9# again.");
    }

    $player = playerById($session['playerId']);
    if ($player === null || empty($player['passwordHash'])) {
        ussdLog('WD_MOMO_PW_NO_HASH', ['playerId' => $session['playerId']]);
        return respEnd("Account error. Please contact support.");
    }

    // Check shared lockout pool BEFORE verifying — protects against an attacker
    // who slipped past EntryState by triggering the lock from web mid-session.
    $minsLeft = playerLockoutCheck((int)$player['id']);
    if ($minsLeft !== null) {
        unset($session['data']['wdAmountPesewas']);
        return respEnd(
            "Too many wrong PIN attempts.\n"
            . "Try again in $minsLeft minute" . ($minsLeft === 1 ? '' : 's') . "."
        );
    }

    if (!verifyPassword(trim($input), $player['passwordHash'])) {
        $newFailCount = playerLockoutBump((int)$player['id']);

        ussdLog('WD_MOMO_PW_WRONG', [
            'playerId'  => $session['playerId'],
            'failCount' => $newFailCount,
        ]);

        if ($newFailCount >= 3) {
            // Lock just tripped (bump set lockedUntil). End the flow.
            unset($session['data']['wdAmountPesewas']);
            return respEnd(
                "Too many wrong PIN attempts.\n"
                . "Account locked for 15 min.\n"
                . "Withdrawal cancelled."
            );
        }

        $remaining = 3 - $newFailCount;
        return respStay(
            "Wrong PIN.\n"
            . "$remaining attempt" . ($remaining === 1 ? '' : 's') . " left.\n"
            . "Try again:"
        );
    }

    // Correct PIN — reset the lockout counter, then proceed with preflight.
    playerLockoutReset((int)$player['id']);

    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    try {
        $pre = withdrawalToMomoPreflight($session['playerId'], $amountPesewas, $clientIp);
    } catch (InvalidArgumentException $e) {
        // Amount range
        return respEnd($e->getMessage());
    } catch (RuntimeException $e) {
        // Cooldown / pending withdrawal / insufficient funds / ineligible
        return respEnd($e->getMessage());
    } catch (Throwable $e) {
        ussdLog('WD_MOMO_PREFLIGHT_UNEXPECTED', [
            'playerId' => $session['playerId'],
            'error'    => $e->getMessage(),
        ]);
        return respEnd("Withdrawal failed. Please try again later.");
    }

    // Clear sensitive flow data
    unset($session['data']['wdAmountPesewas']);

    // END the USSD session AND queue the ANM call for after a 3s wait.
    return respEndWithTask(
        "Withdrawal is on the way.\n"
        . "--\n"
        . formatPesewas($amountPesewas) . " will be sent\n"
        . "to your MoMo wallet.\n"
        . "You will get an SMS.",
        'withdrawal_anm_call',
        ['withdrawalId' => $pre['withdrawalId']]
    );
}
