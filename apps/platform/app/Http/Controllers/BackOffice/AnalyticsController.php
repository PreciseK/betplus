<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Analytics\FunnelService;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.10 (REQ-ANL-006/007) — read-only. Both endpoints read analyticsDailyRollup
 * only, never analyticsEvent directly, so no back-office query can degrade the play
 * path (REQ-ANL-006).
 *
 * Segmentation gap, flagged rather than faked: rollups are keyed on (day, eventName,
 * channel, gameCode, stateCode) — REQ-ANL-007 also asks for app-version and locale
 * segmentation. app_version is captured per-event but not part of the rollup's
 * grouping key; locale isn't captured anywhere in this codebase at all (no field on
 * Player or analyticsEvent). Both endpoints below segment by channel/game/state only.
 */
class AnalyticsController extends Controller
{
    private const DEFAULT_WINDOW_DAYS = 30;

    /** GET /backoffice/v1/analytics/rollups?from=&to=&event_name=&game_code=&state_code=&channel= */
    public function rollups(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveWindow($request);

        $rows = AnalyticsDailyRollup::query()
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('event_name'), fn ($q) => $q->where('eventName', $request->query('event_name')))
            ->when($request->filled('game_code'), fn ($q) => $q->where('gameCode', $request->query('game_code')))
            ->when($request->filled('state_code'), fn ($q) => $q->where('stateCode', $request->query('state_code')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->query('channel')))
            ->orderBy('day')
            ->limit(2000)
            ->get();

        return response()->json([
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows' => $rows->map(fn (AnalyticsDailyRollup $r) => [
                'day' => $r->day->toDateString(),
                'event_name' => $r->eventName,
                'channel' => $r->channel,
                'game_code' => $r->gameCode,
                'state_code' => $r->stateCode,
                'event_count' => $r->eventCount,
                'distinct_player_count' => $r->distinctPlayerCount,
            ]),
        ]);
    }

    /** GET /backoffice/v1/analytics/funnels?from=&to= */
    public function funnels(Request $request, FunnelService $funnels): JsonResponse
    {
        [$from, $to] = $this->resolveWindow($request);

        return response()->json($funnels->compute($from, $to));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function resolveWindow(Request $request): array
    {
        // REQ-BO-021 — never silently load unbounded history.
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to')) : CarbonImmutable::today();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from')) : $to->subDays(self::DEFAULT_WINDOW_DAYS);

        return [$from, $to];
    }
}
