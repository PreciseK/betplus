<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Jobs;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Wallet\WalletService;
use App\Models\LedgerEntry;
use App\Models\MonthlyDrawEntry;
use App\Models\MonthlyDrawPool;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MonthlyDrawTicketAggregationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACCRA_TZ = 'Africa/Accra';
    private const QUALIFYING_STAKE_KOBO = 20000_00; // ₦20,000 in kobo

    public function __construct(
        private readonly ?string $targetMonthPeriod = null // e.g. '2026-08'
    ) {
    }

    public function handle(WalletService $wallet, SeedIssuer $seedIssuer): void
    {
        $now = Carbon::now(self::ACCRA_TZ);
        $period = $this->targetMonthPeriod ?? $now->copy()->subMonth()->format('Y-m');

        $pool = MonthlyDrawPool::firstOrCreate(
            ['monthPeriod' => $period],
            ['status' => 'OPEN']
        );

        if ($pool->status !== 'OPEN') {
            Log::info("MonthlyDrawPool for {$period} is already in {$pool->status} state.");
            return;
        }

        $startOfMonth = Carbon::createFromFormat('Y-m', $period, self::ACCRA_TZ)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        // 1. Calculate platform total turnover from ledgerEntry in that month
        $totalTurnoverKobo = (int) DB::table('ledgerEntry')
            ->join('ledgerAccount', 'ledgerEntry.accountId', '=', 'ledgerAccount.id')
            ->whereIn('ledgerAccount.type', ['PLAYER_PLAY', 'PLAYER_BONUS'])
            ->where('ledgerEntry.direction', 'debit')
            ->whereBetween('ledgerEntry.createdAt', [$startOfMonth, $endOfMonth])
            ->sum('ledgerEntry.amountKobo');

        if ($totalTurnoverKobo <= 0) {
            $pool->update(['status' => 'DISBURSED', 'totalTurnoverKobo' => 0]);
            return;
        }

        // 2. Reserve 1% platform rake for prize pool
        $prizePoolKobo = intdiv($totalTurnoverKobo, 100);
        if ($prizePoolKobo > 0) {
            $wallet->allocateMonthlyDrawPool($prizePoolKobo, 'monthly_draw_pool', $pool->id);
        }

        // 3. Aggregate player turnover and award 1 ticket per ₦20,000 staked
        $playerTurnovers = DB::table('ledgerEntry')
            ->join('ledgerAccount', 'ledgerEntry.accountId', '=', 'ledgerAccount.id')
            ->whereIn('ledgerAccount.type', ['PLAYER_PLAY', 'PLAYER_BONUS'])
            ->where('ledgerEntry.direction', 'debit')
            ->whereBetween('ledgerEntry.createdAt', [$startOfMonth, $endOfMonth])
            ->whereNotNull('ledgerAccount.scope')
            ->select('ledgerAccount.scope as playerId', DB::raw('SUM(ledgerEntry.amountKobo) as totalStakeKobo'))
            ->groupBy('ledgerAccount.scope')
            ->having('totalStakeKobo', '>=', self::QUALIFYING_STAKE_KOBO)
            ->get();

        $currentTicketNumber = 0;
        foreach ($playerTurnovers as $row) {
            $playerId = (int) $row->playerId;
            $turnoverKobo = (int) $row->totalStakeKobo;
            $ticketCount = intdiv($turnoverKobo, self::QUALIFYING_STAKE_KOBO);

            if ($ticketCount <= 0) {
                continue;
            }

            $ticketRangeStart = $currentTicketNumber + 1;
            $ticketRangeEnd = $currentTicketNumber + $ticketCount;
            $currentTicketNumber = $ticketRangeEnd;

            MonthlyDrawEntry::updateOrCreate(
                ['poolId' => $pool->id, 'playerId' => $playerId],
                [
                    'turnoverKobo' => $turnoverKobo,
                    'ticketCount' => $ticketCount,
                    'ticketRangeStart' => $ticketRangeStart,
                    'ticketRangeEnd' => $ticketRangeEnd,
                ]
            );
        }

        $totalTicketsIssued = $currentTicketNumber;
        $pool->update([
            'totalTurnoverKobo' => $totalTurnoverKobo,
            'allocatedPrizePoolKobo' => $prizePoolKobo,
            'totalTicketsIssued' => $totalTicketsIssued,
            'status' => 'SNAPSHOTTED',
        ]);

        if ($totalTicketsIssued === 0 || $prizePoolKobo <= 0) {
            $pool->update(['status' => 'DISBURSED']);
            return;
        }

        // 4. Provably Fair Draw using certified seed
        $seed = $seedIssuer->issue();
        $drawSeedHex = $seed->seedHex;

        // Rank prizes: 1st: 50%, 2nd: 30%, 3rd: 20%
        $rankPercentages = [1 => 50, 2 => 30, 3 => 20];
        $winners = [];

        for ($rank = 1; $rank <= 3; $rank++) {
            // Deterministic hash per rank from seed
            $hash = hash_hmac('sha256', "rank_{$rank}_{$pool->id}", $drawSeedHex);
            $winningTicket = (int) (hexdec(substr($hash, 0, 8)) % $totalTicketsIssued) + 1;

            $entry = MonthlyDrawEntry::where('poolId', $pool->id)
                ->where('ticketRangeStart', '<=', $winningTicket)
                ->where('ticketRangeEnd', '>=', $winningTicket)
                ->first();

            if ($entry !== null) {
                $pct = $rankPercentages[$rank];
                $prizeKobo = intdiv($prizePoolKobo * $pct, 100);

                $player = Player::find($entry->playerId);
                if ($player !== null && $prizeKobo > 0) {
                    $wallet->settleMonthlyDrawPrize($player, $prizeKobo, 'monthly_draw', $pool->id);

                    $winners[] = [
                        'rank' => $rank,
                        'playerId' => $player->id,
                        'msisdn_masked' => substr($player->msisdn, 0, 4) . '****' . substr($player->msisdn, -3),
                        'winningTicket' => $winningTicket,
                        'prizeKobo' => $prizeKobo,
                    ];
                }
            }
        }

        $pool->update([
            'status' => 'DISBURSED',
            'drawSeedRef' => (string) $seed->id,
            'winnersJson' => $winners,
            'drawnAt' => Carbon::now(self::ACCRA_TZ),
        ]);

        Log::info("Monthly draw for {$period} completed. Prizes disbursed: {$prizePoolKobo} kobo.");
    }
}
