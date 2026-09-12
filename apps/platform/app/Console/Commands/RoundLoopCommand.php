<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\BirdEscape\RoundLifecycleService;
use Illuminate\Console\Command;
use Throwable;

/**
 * A supervised long-running process (systemd unit / supervisor daemon in production —
 * REQ-HOST-005's "queue workers are long-running supervised processes, never cron"
 * principle, applied to a round loop instead of a queue worker), not a scheduled
 * artisan command. `--once` runs a single tick and exits, for tests/CI to exercise the
 * command itself without an infinite loop.
 */
class RoundLoopCommand extends Command
{
    protected $signature = 'birdescape:round-loop {--once : Run a single tick and exit, instead of looping forever}';

    protected $description = 'Advance the live BirdEscape round (betting -> flying -> crashed -> next round) on a tight loop.';

    private const TICK_INTERVAL_MICROSECONDS = 200_000; // 200ms

    public function handle(RoundLifecycleService $lifecycle): int
    {
        do {
            try {
                $round = $lifecycle->currentOrNextRound();
                $lifecycle->advanceIfDue($round);
            } catch (Throwable $e) {
                // A transient DB hiccup on one tick must not crash the whole supervised
                // process — log and keep ticking; the next iteration re-reads fresh state.
                $this->error('[round-loop] tick failed: ' . $e->getMessage());
                report($e);
            }

            if (!$this->option('once')) {
                $sleepMicros = match ($round->status) {
                    'FLYING' => 250_000, // 250ms during flight
                    default => 500_000,  // 500ms during betting and post-crash
                };
                usleep($sleepMicros);
            }
        } while (!$this->option('once'));

        return self::SUCCESS;
    }
}
