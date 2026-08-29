<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\DailyRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RollupAnalyticsCommand extends Command
{
    protected $signature = 'analytics:rollup {--day= : Y-m-d, defaults to yesterday}';
    protected $description = 'Story 6.10 — pre-aggregate one day of analyticsEvent into analyticsDailyRollup (REQ-ANL-006)';

    public function handle(DailyRollupService $rollup): int
    {
        $day = $this->option('day') !== null
            ? CarbonImmutable::parse($this->option('day'))
            : CarbonImmutable::yesterday();

        $groups = $rollup->rollupDay($day);

        $this->info("Rolled up {$day->toDateString()}: {$groups} (eventName, channel, game, state) groups.");

        return self::SUCCESS;
    }
}
