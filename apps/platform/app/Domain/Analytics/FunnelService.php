<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonImmutable;

/**
 * Story 6.10 / REQ-ANL-007 — the six required funnels, computed from
 * analyticsDailyRollup (REQ-ANL-006: dashboards never read raw events).
 *
 * ponytail: rollups are pre-aggregated by (day, eventName, channel, gameCode,
 * stateCode) with no player-level linkage retained (that's what makes them safe to
 * read without degrading the play path) — so a "funnel" here is stage-to-stage
 * volume within the window, not "how many distinct players completed every step in
 * order." A true per-player conversion funnel would need to query raw analyticsEvent
 * joined by playerIdHash, which is exactly what REQ-ANL-006 says dashboards must not
 * do. Upgrade path if per-player conversion is ever required: a separate, explicitly
 * player-level funnel table computed by the same nightly job, not ad hoc raw reads.
 *
 * USSD play and cross-game funnels are not fabricated: no USSD channel and no second
 * game exist in this codebase yet, so those two are reported as unmeasurable rather
 * than given an invented number.
 */
final class FunnelService
{
    /** @return array{window: array{from: string, to: string}, funnels: array<string, array<string, mixed>>} */
    public function compute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = AnalyticsDailyRollup::query()
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('eventName, SUM(eventCount) AS total')
            ->groupBy('eventName')
            ->pluck('total', 'eventName');

        $step = fn (string $eventName): int => (int) ($totals[$eventName] ?? 0);
        $rate = function (int $numerator, int $denominator): ?float {
            return $denominator > 0 ? round($numerator / $denominator, 4) : null;
        };

        $acquisition = [
            ['step' => 'player_registered', 'eventCount' => $step('player_registered')],
            ['step' => 'nin_verified', 'eventCount' => $step('nin_verified')],
            ['step' => 'ticket_purchased', 'eventCount' => $step('ticket_purchased')],
        ];
        $funding = [
            ['step' => 'nin_verified', 'eventCount' => $step('nin_verified')],
            ['step' => 'deposit_completed', 'eventCount' => $step('deposit_completed')],
        ];
        $payout = [
            ['step' => 'ticket_purchased', 'eventCount' => $step('ticket_purchased')],
            ['step' => 'payout_completed', 'eventCount' => $step('payout_completed')],
        ];
        $geoAttribution = [
            ['step' => 'state_attributed', 'eventCount' => $step('state_attributed')],
            ['step' => 'ticket_purchased', 'eventCount' => $step('ticket_purchased')],
        ];

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'funnels' => [
                'acquisition_to_first_paid_play' => [
                    'measurable' => true,
                    'steps' => $acquisition,
                    'conversionRate' => $rate($acquisition[2]['eventCount'], $acquisition[0]['eventCount']),
                ],
                'ussd_play' => [
                    'measurable' => false,
                    'reason' => 'No USSD channel exists yet — AnalyticsEventRecorder only ever receives channel=web today.',
                ],
                'funding' => [
                    'measurable' => true,
                    'steps' => $funding,
                    'conversionRate' => $rate($funding[1]['eventCount'], $funding[0]['eventCount']),
                ],
                'payout' => [
                    'measurable' => true,
                    'steps' => $payout,
                    'conversionRate' => $rate($payout[1]['eventCount'], $payout[0]['eventCount']),
                ],
                'cross_game' => [
                    'measurable' => false,
                    'reason' => 'Only one game (BlackRed) exists yet — a cross-game funnel has nothing to cross to.',
                ],
                'geo_attribution' => [
                    'measurable' => true,
                    'steps' => $geoAttribution,
                    'conversionRate' => $rate($geoAttribution[1]['eventCount'], $geoAttribution[0]['eventCount']),
                ],
            ],
        ];
    }
}
