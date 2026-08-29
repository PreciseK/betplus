<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Story 2.7 (REQ-WAL-043) — this only fires if something is actually running
// `php artisan schedule:work` (or a cron entry calling `schedule:run` every minute) on
// the deployed server; neither exists yet (Story 1.4, parked). Runs after the day's
// activity has settled, well before the T+1 measurement window M11 targets.
Schedule::command('ledger:reconcile')->dailyAt('01:00');

// Story 4.7/4.8 — REQ-FLOAT-002 wants 60s polling from a supervised worker; this is
// the scheduler-granularity approximation (see SnapshotFloatCommand's doc comment).
Schedule::command('payout:snapshot-float')->everyMinute();

// Story 6.10 (REQ-ANL-006) — rolls up the prior UTC day once it's fully closed, ahead
// of anyone opening a back-office dashboard for it.
Schedule::command('analytics:rollup')->dailyAt('01:30');

// Story 7.8 (REQ-HG-039) — daily reconciliation between submitted second-chance
// entries and partner-confirmed entries.
Schedule::command('heritage:reconcile-second-chance')->dailyAt('02:00');

// Story 7.9 (REQ-HG-038) — polls for entries the partner has resulted since the last
// run. Scheduler-granularity approximation of "as soon as results are in", same
// category as SnapshotFloatCommand's own doc comment.
Schedule::command('heritage:notify-second-chance-results')->everyFiveMinutes();

// Story 8.1 (REQ-USSD-005) — "purges expired sessions every 5 minutes."
Schedule::command('ussd:cleanup-sessions')->everyFiveMinutes();
