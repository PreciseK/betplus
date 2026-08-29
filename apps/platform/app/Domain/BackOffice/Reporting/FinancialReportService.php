<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\Reporting;

use App\Models\Payout;
use App\Models\PrizeTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Story 6.9 / REQ-BO-007 — slices real stakes/payouts/GGR/RTP/tax/float activity by
 * game and state for an arbitrary date range. Every figure here is derived from data
 * this codebase actually writes (ticket, ticketOutcome, payout, ledgerEntry) — nothing
 * is fabricated.
 *
 * Two lines are honestly incomplete rather than faked:
 *  - providerFeesKobo is always 0: no MDR/provider-fee accrual is posted anywhere in
 *    this codebase yet (REQ-FLOAT-007 is unimplemented) — there is no real number to
 *    report, so this reports the true current state (untracked) instead of inventing one.
 *  - GGR levy is 0 for the same reason: GGR_LEVY_PAYABLE is a declared ledger account
 *    type (WalletService::ACCOUNT_TYPES) that nothing ever posts to.
 */
final class FinancialReportService
{
    /** @return array{filter: array<string, mixed>, rows: list<array<string, mixed>>, withdrawals: array<string, mixed>, floatMovementsByState: list<array<string, mixed>>} */
    public function generate(CarbonImmutable $from, CarbonImmutable $to, ?string $gameCode = null, ?string $stateCode = null): array
    {
        $rows = $this->gameStateRows($from, $to, $gameCode, $stateCode);

        return [
            'filter' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'game_code' => $gameCode,
                'state_code' => $stateCode,
            ],
            'rows' => $rows,
            // Withdrawals aren't attributed to a game (REQ-PO withdrawals are a
            // player-initiated Winnings Balance movement, not a ticket outcome), so
            // they're reported as one cross-game total rather than forced into a
            // game/state slice they don't actually have.
            'withdrawals' => $this->withdrawalsSummary($from, $to, $stateCode),
            'floatMovementsByState' => $this->floatMovementsByState($from, $to, $stateCode),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function gameStateRows(CarbonImmutable $from, CarbonImmutable $to, ?string $gameCode, ?string $stateCode): array
    {
        // DB::table (not the Ticket model) — the joined ticketOutcome columns aren't
        // real attributes on the Ticket model, and a query builder row is a plain
        // stdClass rather than a typed Eloquent instance.
        $ticketsQuery = DB::table('ticket')
            ->join('ticketOutcome', 'ticketOutcome.ticketId', '=', 'ticket.id')
            ->whereBetween('ticket.createdAt', [$from, $to])
            ->when($gameCode !== null, fn ($q) => $q->where('ticket.gameCode', $gameCode))
            ->when($stateCode !== null, fn ($q) => $q->where('ticket.stateCode', $stateCode))
            ->select([
                'ticket.id', 'ticket.gameCode', 'ticket.stateCode', 'ticket.stakeKobo',
                'ticket.positions', 'ticket.prizeTableVersion',
                'ticketOutcome.won', 'ticketOutcome.grossPrizeKobo', 'ticketOutcome.netCreditKobo',
                'ticketOutcome.taxWithheldKobo',
            ]);

        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];
        $tierLookup = [];

        $ticketsQuery->orderBy('ticket.id')->chunk(500, function ($tickets) use (&$grouped, &$tierLookup) {
            foreach ($tickets as $ticket) {
                $key = $ticket->gameCode . '|' . $ticket->stateCode;
                $grouped[$key] ??= [
                    'gameCode' => $ticket->gameCode,
                    'stateCode' => $ticket->stateCode,
                    'ticketCount' => 0,
                    'stakesKobo' => 0,
                    'winningTicketCount' => 0,
                    'grossPrizesKobo' => 0,
                    'netPrizesKobo' => 0,
                    'taxWithheldKobo' => 0,
                    'modelledPrizesKobo' => 0,
                ];

                $grouped[$key]['ticketCount']++;
                $grouped[$key]['stakesKobo'] += $ticket->stakeKobo;
                if ($ticket->won) {
                    $grouped[$key]['winningTicketCount']++;
                    $grouped[$key]['grossPrizesKobo'] += $ticket->grossPrizeKobo;
                    $grouped[$key]['netPrizesKobo'] += $ticket->netCreditKobo;
                }
                $grouped[$key]['taxWithheldKobo'] += $ticket->taxWithheldKobo;

                $tier = $this->resolveTier($tierLookup, $ticket->gameCode, $ticket->stateCode, $ticket->prizeTableVersion, $ticket->positions);
                if ($tier !== null) {
                    $probability = $tier->probabilityNumerator / $tier->probabilityDenominator;
                    $grouped[$key]['modelledPrizesKobo'] += (int) round($ticket->stakeKobo * $probability * $tier->multiplierHundredths / 100);
                }
            }
        });

        return array_values(array_map(function (array $row): array {
            $row['rtpActualBasisPoints'] = $row['stakesKobo'] > 0
                ? (int) round($row['grossPrizesKobo'] / $row['stakesKobo'] * 10_000)
                : 0;
            $row['rtpModelledBasisPoints'] = $row['stakesKobo'] > 0
                ? (int) round($row['modelledPrizesKobo'] / $row['stakesKobo'] * 10_000)
                : 0;
            $row['ggrKobo'] = $row['stakesKobo'] - $row['grossPrizesKobo'];
            // Honestly untracked — see class doc.
            $row['providerFeesKobo'] = 0;
            $row['ggrLevyKobo'] = 0;
            unset($row['modelledPrizesKobo']);

            return $row;
        }, $grouped));
    }

    /**
     * Matches the same tier a ticket actually resolved against: exact-state prize
     * table preferred, falling back to the state-agnostic (stateCode null) table,
     * mirroring PrizeTableResolver's own precedence.
     *
     * @param array<string, PrizeTable|false> $cache
     */
    private function resolveTier(array &$cache, string $gameCode, string $stateCode, string $version, int $positions): ?object
    {
        $cacheKey = "$gameCode|$stateCode|$version";
        if (!array_key_exists($cacheKey, $cache)) {
            $table = PrizeTable::where('gameCode', $gameCode)
                ->where('version', $version)
                ->where(function ($q) use ($stateCode) {
                    $q->where('stateCode', $stateCode)->orWhereNull('stateCode');
                })
                ->orderByRaw('stateCode IS NULL') // exact-state match first
                ->with('tiers')
                ->first();
            $cache[$cacheKey] = $table ?? false;
        }

        $table = $cache[$cacheKey];
        if ($table === false) {
            return null;
        }

        return $table->tiers->firstWhere('positions', $positions);
    }

    /** @return array<string, mixed> */
    private function withdrawalsSummary(CarbonImmutable $from, CarbonImmutable $to, ?string $stateCode): array
    {
        // Withdrawals carry no stateCode of their own; $stateCode here is accepted for
        // filter-shape symmetry with the game/state rows but has nothing to scope
        // against, so it's deliberately unused rather than silently misapplied.
        $base = Payout::where('kind', 'withdrawal')->whereBetween('createdAt', [$from, $to]);

        return [
            'requestedCount' => (clone $base)->count(),
            'requestedKobo' => (int) (clone $base)->sum('amountKobo'),
            'confirmedCount' => (clone $base)->whereNotNull('confirmedAt')->count(),
            'confirmedKobo' => (int) (clone $base)->whereNotNull('confirmedAt')->sum('amountKobo'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function floatMovementsByState(CarbonImmutable $from, CarbonImmutable $to, ?string $stateCode): array
    {
        // REQ-BO-007 "float movements" — real net flow through OPAY_FLOAT in the
        // period, sliced by state (funded from confirmWithdrawalPayout/
        // confirmPrizePayout, the only two writers of this account — see WalletService).
        $rows = DB::table('ledgerEntry')
            ->join('ledgerAccount', 'ledgerAccount.id', '=', 'ledgerEntry.accountId')
            ->where('ledgerAccount.type', 'OPAY_FLOAT')
            ->whereBetween('ledgerEntry.createdAt', [$from, $to])
            ->when($stateCode !== null, fn ($q) => $q->where('ledgerEntry.stateCode', $stateCode))
            ->select('ledgerEntry.stateCode')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) AS creditedKobo")
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) AS debitedKobo")
            ->groupBy('ledgerEntry.stateCode')
            ->get();

        return $rows->map(fn ($row) => [
            'stateCode' => $row->stateCode,
            'creditedKobo' => (int) $row->creditedKobo,
            'debitedKobo' => (int) $row->debitedKobo,
            'netKobo' => (int) $row->creditedKobo - (int) $row->debitedKobo,
        ])->all();
    }
}
