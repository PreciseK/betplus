<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Collection;
use App\Models\LedgerDiscrepancy;
use App\Models\Payout;
use App\Models\Player;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use App\Models\VelocityFlag;
use Carbon\CarbonImmutable;

/**
 * REQ-BO metrics for one calendar day, queried directly against source tables — unlike
 * FunnelService, analyticsDailyRollup has no money columns at all, so this can't reuse
 * that rollup. Deposits/payouts/new-players/verified-players are platform-wide by
 * nature (a deposit isn't tied to a game) so $gameCode only scopes the ticket-derived
 * metrics (stakes, wins, active players).
 */
final class DailySummaryService
{
    /** @return array<string, mixed> */
    public function forDay(CarbonImmutable $day, ?string $gameCode): array
    {
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        $ticketQuery = Ticket::whereBetween('createdAt', [$start, $end])
            ->when($gameCode, fn ($q, $code) => $q->where('gameCode', $code));

        $grossStakesKobo = (int) (clone $ticketQuery)->sum('stakeKobo');
        $ticketIds = (clone $ticketQuery)->pluck('id');
        $grossWinsKobo = (int) TicketOutcome::whereIn('ticketId', $ticketIds)->sum('grossPrizeKobo');

        return [
            'date' => $day->toDateString(),
            'game_code' => $gameCode,
            'gross_stakes_kobo' => $grossStakesKobo,
            'gross_wins_kobo' => $grossWinsKobo,
            'net_gaming_revenue_kobo' => $grossStakesKobo - $grossWinsKobo,
            'deposits_kobo' => (int) Collection::where('status', 'paid')->whereBetween('paidAt', [$start, $end])->sum('amountKobo'),
            'payouts_kobo' => (int) Payout::where('providerStatus', 'SUCCESS')->whereBetween('confirmedAt', [$start, $end])->sum('amountKobo'),
            'new_players' => Player::whereBetween('createdAt', [$start, $end])->count(),
            // Distinct ticket purchasers that day — a sound proxy for "active"; player.lastLoginAt
            // is a single latest-value column, so filtering it by day would undercount anyone who
            // logged in earlier and stayed active.
            'active_players' => (clone $ticketQuery)->distinct('playerId')->count('playerId'),
            // A current total, not a delta for this day — kycStatus has no "verified on" column.
            'verified_players_total' => Player::where('kycStatus', 'verified')->count(),
            'reconciliation_exceptions' => LedgerDiscrepancy::whereBetween('detectedAt', [$start, $end])->count(),
            'payout_holds' => Payout::where('manualReviewRequired', true)->whereBetween('createdAt', [$start, $end])->count(),
            'safer_play_reviews_opened' => VelocityFlag::whereBetween('createdAt', [$start, $end])->count(),
        ];
    }
}
