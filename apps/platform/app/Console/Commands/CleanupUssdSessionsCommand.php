<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UssdSession;
use Illuminate\Console\Command;

/**
 * Story 8.1 (REQ-USSD-005) — "background cleanup purges expired sessions every 5
 * minutes." This marks the platform's audit mirror stale; apps/ussd's own
 * SessionStore expires its live turn-state independently (file-mtime/TTL check at
 * read time — see that app's SessionStore doc comment), since this command has no
 * access to apps/ussd's session store by design (separate deployable, REQ-ARCH-001
 * network-boundary discipline applies here too).
 */
class CleanupUssdSessionsCommand extends Command
{
    protected $signature = 'ussd:cleanup-sessions';
    protected $description = 'Story 8.1 — mark stale ussdSession audit rows expired (REQ-USSD-005)';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('ussd.session_ttl_minutes'));

        $count = UssdSession::where('status', 'active')
            ->where('lastTurnAt', '<', $cutoff)
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} stale USSD session(s).");

        return self::SUCCESS;
    }
}
