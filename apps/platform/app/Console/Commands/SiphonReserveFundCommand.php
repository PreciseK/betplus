<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Economics\GameReserveFundSiphonService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SiphonReserveFundCommand extends Command
{
    protected $signature = 'economics:siphon-reserve-fund {--day= : Y-m-d, defaults to yesterday}';
    protected $description = 'Phase 2 — moves BALANCED_HYBRID reserveSiphonBps of each game\'s closed-day net GGR into the RESERVE_FUND ledger account';

    public function handle(GameReserveFundSiphonService $siphon): int
    {
        $day = $this->option('day') !== null
            ? CarbonImmutable::parse($this->option('day'))
            : CarbonImmutable::yesterday();

        $count = $siphon->siphonDay($day);

        $this->info("Siphoned reserve fund for {$day->toDateString()}: {$count} game(s).");

        return self::SUCCESS;
    }
}
