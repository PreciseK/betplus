<?php
/**
 * Logging.
 *
 * One function: ussdLog($message, $context).
 *
 * Writes JSON-tagged lines to logs/ussd.log. Matches the Sika Bumm pattern
 * but with structured context instead of stringy concatenation.
 *
 * Why not Monolog: this is one file, ~30 lines, with no rotation needs (we
 * rotate via OS logrotate or a weekly cron). Monolog would be 200KB of
 * dependency for the same effect.
 *
 * Failure mode: if the log file can't be written (permissions, disk full),
 * we silently swallow — logging must NEVER take down a USSD request.
 */

function ussdLog(string $message, array $context = []): void
{
    static $path = null;

    if ($path === null) {
        global $USSD_CONFIG;
        $path = $USSD_CONFIG['log']['path'] ?? __DIR__ . '/../logs/ussd.log';
    }

    $line = sprintf(
        "%s | %s%s\n",
        date('Y-m-d H:i:s'),
        $message,
        empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // @ suppresses warning if file isn't writable. We don't want logging
    // failures to crash USSD requests — log loss is acceptable; crashes aren't.
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
