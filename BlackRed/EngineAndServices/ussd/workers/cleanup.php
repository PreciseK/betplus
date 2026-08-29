<?php
/**
 * Session cleanup cron.
 *
 * Run every 5 minutes via cPanel cron job:
 *   *\/5 * * * * /usr/local/bin/php /home/amoamvfc/ussd/workers/cleanup.php
 *
 * (No leading slash escape needed in actual crontab — that's just markdown
 * escaping the comment above.)
 *
 * Deletes ussdSession rows older than 7 minutes. Nalo terminates abandoned
 * sessions at ~120 seconds, so anything older is definitely stale.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php missing\n");
    exit(1);
}
$USSD_CONFIG = require $configPath;

require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/session.php';

try {
    $deleted = sessionPurgeOlderThan(7);
    ussdLog('CLEANUP', ['deleted' => $deleted]);
    echo "Purged $deleted expired sessions.\n";
} catch (Throwable $e) {
    ussdLog('CLEANUP_ERROR', ['error' => $e->getMessage()]);
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}
