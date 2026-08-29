<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payout\Float\FloatService;
use Illuminate\Console\Command;

/**
 * Story 4.7/4.8. REQ-FLOAT-002 asks for polling every 60 seconds and after every
 * payout batch — real continuous 60s polling needs a supervised worker loop, not
 * cron (D-09/REQ-HOST-005 says workers run under systemd, never cron); this command
 * is the polling unit, scheduled at the scheduler's minute granularity as the nearest
 * practical approximation until that worker exists. Genuine infra gap, not silently
 * dropped — same category as Story 1.4.
 */
class SnapshotFloatCommand extends Command
{
    protected $signature = 'payout:snapshot-float';
    protected $description = 'Story 4.7 — poll OPay float balance and evaluate alert thresholds (REQ-FLOAT-001..004)';

    public function handle(FloatService $float): int
    {
        $snapshot = $float->snapshot();

        $this->info("Float balance: {$snapshot->opayBalanceKobo} kobo — alert state: {$snapshot->alertState}");

        return $snapshot->alertState === 'ok' || $snapshot->alertState === 'warning' ? self::SUCCESS : self::FAILURE;
    }
}
