<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Heritage\HeritageEngineClient;
use App\Domain\Tax\TaxEngine;
use App\Domain\Ticket\SendTicketReceiptSms;
use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\PoolDraw;
use App\Models\PoolEntry;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Settles one Heritage pari-mutuel pool: draws one shared 5-of-9 winning
 * combination, splits the net pool into four match-count sub-pools by the
 * configured tier_allocation_bps, and within each sub-pool pays entries at that
 * exact match count proportional to stake — Heritage's existing tiered payout
 * structure, carried into the pool model instead of collapsed to exact-match-only.
 * See docs/superpowers/specs/2026-09-15-pari-mutuel-pool-design.md §6.
 */
final class HeritagePoolSettlementService
{
    private const GAME_CODE = 'HERITAGE';
    private const TIERS = [5, 4, 3, 2];

    public function __construct(
        private readonly SeedIssuer $seedIssuer,
        private readonly HeritageEngineClient $engine,
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
        $winningPositions = $this->engine->drawPoolOutcome($seed->seedHex);

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get()
            ->map(function (PoolEntry $entry) use ($winningPositions) {
                $entry->matchTier = count(array_intersect($entry->predictionJson, $winningPositions));

                return $entry;
            });

        $rakeKobo = intdiv($pool->grossStakedKobo * $params->rakeBps, 10_000);
        if ($pool->grossStakedKobo > 0) {
            $this->wallet->closePariMutuelPool($pool->grossStakedKobo, $rakeKobo, 'pool_draw', $pool->id);
        }
        $netPoolKobo = $pool->grossStakedKobo - $rakeKobo;

        $rolloverIn = $pool->tierRolloverJson ?? [];
        $tierRolloverOut = [];
        $topLevelAllocatedKobo = 0;
        $allPayouts = [];

        foreach (self::TIERS as $tier) {
            $tierAllocationBps = $params->tierAllocationBps[$tier] ?? 0;
            $tierBudgetFromDrawKobo = intdiv($netPoolKobo * $tierAllocationBps, 10_000);
            $topLevelAllocatedKobo += $tierBudgetFromDrawKobo;
            $tierBudgetKobo = $tierBudgetFromDrawKobo + ($rolloverIn[$tier] ?? 0);

            $tierEntries = $entries->where('matchTier', $tier);
            $winnerStakes = $tierEntries->pluck('stakeKobo', 'id')->all();
            $split = $this->calculator->split($tierBudgetKobo, $winnerStakes);

            foreach ($split['payouts'] as $entryId => $payoutKobo) {
                $allPayouts[$entryId] = $payoutKobo;
            }

            if ($tierEntries->isNotEmpty() && $split['remainderKobo'] > 0) {
                $this->wallet->sweepPariMutuelRemainder($split['remainderKobo'], 'pool_draw', $pool->id);
            }

            $tierRolloverOut[$tier] = $tierEntries->isEmpty() ? $tierBudgetKobo : 0;
        }

        // Dust from splitting netPoolKobo into 4 integer tier shares (at most 3
        // kobo) isn't anyone's entitlement and isn't worth rolling forward — every
        // future draw would just generate its own — so it goes to HOUSE_REVENUE now.
        $topLevelDustKobo = $netPoolKobo - $topLevelAllocatedKobo;
        if ($topLevelDustKobo > 0) {
            $this->wallet->sweepPariMutuelRemainder($topLevelDustKobo, 'pool_draw', $pool->id);
        }

        foreach ($entries as $entry) {
            $won = array_key_exists($entry->id, $allPayouts);
            $payoutKobo = $allPayouts[$entry->id] ?? 0;
            $this->settleEntry($entry, $won, $payoutKobo, $winningPositions, $seed->seedHex);
        }

        $pool->update([
            'status' => 'settled',
            'drawnOutcomeJson' => ['winningPositions' => $winningPositions],
            'fairnessSeedId' => $seed->id,
            'rakeKobo' => $rakeKobo,
            'netPoolKobo' => $netPoolKobo,
            'drawnAt' => now(),
            'settledAt' => now(),
        ]);

        $this->poolDraws->openPool($pool->gameCode, $pool->poolKey, $params->poolWindowMinutes, 0, $tierRolloverOut, $pool->closesAt);

        return true;
    }

    /** @param list<int> $winningPositions */
    private function settleEntry(PoolEntry $entry, bool $won, int $payoutKobo, array $winningPositions, string $seedHex): void
    {
        DB::transaction(function () use ($entry, $won, $payoutKobo, $winningPositions, $seedHex) {
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
                'resultJson' => [
                    'winning_positions' => $winningPositions,
                    'selected_positions' => $entry->predictionJson,
                    'match_count' => $entry->matchTier,
                ],
                'won' => $won,
                'grossPrizeKobo' => $payoutKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => hash('sha256', $seedHex . '|' . implode(',', $entry->predictionJson) . '|' . implode(',', $winningPositions)),
            ]);

            $entry->update(['won' => $won, 'payoutKobo' => $payoutKobo, 'matchTier' => $entry->matchTier]);
            $ticket->update(['status' => 'SETTLED']);

            $this->dailyLedger->recordSettlement(self::GAME_CODE, $entry->stakeKobo, $payoutKobo);

            $this->sms->send($player, $ticket->fresh('outcome'));
        });
    }
}
