<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Story 6.10 / REQ-ANL-006 — pre-aggregates one day of analyticsEvent into
 * analyticsDailyRollup so dashboards never read raw events. Recomputes the whole day
 * (delete-then-insert) rather than upserting: nullable gameCode/stateCode mean two
 * NULLs never satisfy a unique constraint in either sqlite or MySQL, so an upsert on
 * (day, eventName, channel, gameCode, stateCode) would silently duplicate rows for
 * every event that has no game/state (sign_in_completed, nin_verified, ...) on a
 * second run — recompute-from-scratch sidesteps that instead of working around it.
 */
final class DailyRollupService
{
    public function rollupDay(CarbonImmutable $day): int
    {
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        // DB::table (not the AnalyticsEvent model) — these aggregate aliases aren't
        // real columns on the model, and a query builder row is a plain stdClass.
        $rows = DB::table('analyticsEvent')
            ->whereBetween('occurredAt', [$start, $end])
            ->select(['eventName', 'channel', 'gameCode', 'stateCode'])
            ->selectRaw('COUNT(*) AS eventCount')
            ->selectRaw('COUNT(DISTINCT playerIdHash) AS distinctPlayerCount')
            ->groupBy(['eventName', 'channel', 'gameCode', 'stateCode'])
            ->get();

        DB::transaction(function () use ($day, $rows) {
            AnalyticsDailyRollup::whereDate('day', $day->toDateString())->delete();

            foreach ($rows as $row) {
                AnalyticsDailyRollup::create([
                    'day' => $day->toDateString(),
                    'eventName' => $row->eventName,
                    'channel' => $row->channel,
                    'gameCode' => $row->gameCode,
                    'stateCode' => $row->stateCode,
                    'eventCount' => (int) $row->eventCount,
                    'distinctPlayerCount' => (int) $row->distinctPlayerCount,
                    'computedAt' => now(),
                ]);
            }
        });

        return $rows->count();
    }
}
