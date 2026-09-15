<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Engine\BlackRed\DeckDraw;
use App\Domain\Games\Engine\BlackRed\Digest;
use App\Domain\Tax\TaxEngine;
use App\Domain\Ticket\SendTicketReceiptSms;
use App\Domain\Wallet\WalletService;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\PoolDraw;
use App\Models\PoolEntry;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Settles one BlackRed pari-mutuel pool: draws the one shared winning sequence for
 * that pool's pick-length, splits the net pool among exact matches proportional to
 * stake, and opens the next pool. See docs/superpowers/specs/
 * 2026-09-15-pari-mutuel-pool-design.md §6.
 */
final class BlackRedPoolSettlementService
{
    public function __construct(
        private readonly SeedIssuer $seedIssuer,
        private readonly TaxEngine $tax,
        private readonly WalletService $wallet,
        private readonly PoolPayoutCalculator $calculator,
        private readonly PoolDrawService $poolDraws,
        private readonly GameDailyLedgerService $dailyLedger,
        private readonly SendTicketReceiptSms $sms,
    ) {
    }

    /** @return bool false if the pool was already closed by a concurrent run */
    public function settle(PoolDraw $pool, PariMutuelPoolParams $params): bool
    {
        $affected = PoolDraw::where('id', $pool->id)->where('status', 'open')->update(['status' => 'closed']);
        if ($affected !== 1) {
            return false;
        }

        $seed = $this->seedIssuer->issue();
        $drawnSequence = DeckDraw::draw($seed->seedHex, (int) $pool->poolKey);

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get();
        $rakeKobo = intdiv($pool->grossStakedKobo * $params->rakeBps, 10_000);

        if ($pool->grossStakedKobo > 0) {
            $this->wallet->closePariMutuelPool($pool->grossStakedKobo, $rakeKobo, 'pool_draw', $pool->id);
        }

        $netPoolKobo = ($pool->grossStakedKobo - $rakeKobo) + $pool->rolloverInKobo;

        $winners = $entries->filter(fn (PoolEntry $entry) => $entry->predictionJson === $drawnSequence);
        $winnerStakes = $winners->pluck('stakeKobo', 'id')->all();
        $split = $this->calculator->split($netPoolKobo, $winnerStakes);

        foreach ($entries as $entry) {
            $won = array_key_exists($entry->id, $split['payouts']);
            $payoutKobo = $split['payouts'][$entry->id] ?? 0;
            $this->settleEntry($entry, $won, $payoutKobo, $drawnSequence, $seed->seedHex);
        }

        // With no winners, PoolPayoutCalculator reports the whole net pool as
        // "remainder" — that's the rollover, not house dust, and must stay in
        // PRIZE_LIABILITY to back next draw's carried-forward pot. Only sweep a
        // real leftover-after-distribution when there was someone to distribute to.
        if ($winners->isNotEmpty() && $split['remainderKobo'] > 0) {
            $this->wallet->sweepPariMutuelRemainder($split['remainderKobo'], 'pool_draw', $pool->id);
        }

        $rolloverOutKobo = $winners->isEmpty() ? $netPoolKobo : 0;

        $pool->update([
            'status' => 'settled',
            'drawnOutcomeJson' => ['sequence' => implode('', $drawnSequence)],
            'fairnessSeedId' => $seed->id,
            'rakeKobo' => $rakeKobo,
            'netPoolKobo' => $netPoolKobo,
            'drawnAt' => now(),
            'settledAt' => now(),
        ]);

        $this->poolDraws->openPool($pool->gameCode, $pool->poolKey, $params->poolWindowMinutes, $rolloverOutKobo, null, $pool->closesAt);

        return true;
    }

    /** @param list<string> $drawnSequence */
    private function settleEntry(PoolEntry $entry, bool $won, int $payoutKobo, array $drawnSequence, string $seedHex): void
    {
        DB::transaction(function () use ($entry, $won, $payoutKobo, $drawnSequence, $seedHex) {
            $ticket = Ticket::where('id', $entry->ticketId)->where('status', 'PENDING_DRAW')->lockForUpdate()->first();
            if ($ticket === null) {
                return; // already settled by a concurrent run
            }

            $player = Player::findOrFail($entry->playerId);

            $taxWithheldKobo = 0;
            $netCreditKobo = 0;
            $taxRateBasisPoints = 0;
            $taxBasisLabel = '';
            $taxRulesetVersion = '';

            if ($won && $payoutKobo > 0) {
                $withholding = $this->tax->withhold($payoutKobo, $player);
                $taxWithheldKobo = $withholding->taxWithheldKobo;
                $netCreditKobo = $withholding->netCreditKobo;
                $taxRateBasisPoints = $withholding->rateBasisPoints;
                $taxBasisLabel = $withholding->basisLabel;
                $taxRulesetVersion = $withholding->rulesetVersion;

                $this->wallet->settlePariMutuelWin($player, $payoutKobo, $taxWithheldKobo, $netCreditKobo, 'ticket', $ticket->id, $ticket->stateCode);
            }

            TicketOutcome::create([
                'ticketId' => $ticket->id,
                'resultJson' => $drawnSequence,
                'won' => $won,
                'grossPrizeKobo' => $payoutKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => Digest::of($seedHex, $entry->predictionJson, $drawnSequence),
            ]);

            $entry->update(['won' => $won, 'payoutKobo' => $payoutKobo]);
            $ticket->update(['status' => 'SETTLED']);

            $this->dailyLedger->recordSettlement('BLACKRED', $entry->stakeKobo, $payoutKobo);

            $this->sms->send($player, $ticket->fresh('outcome'));
        });
    }
}
