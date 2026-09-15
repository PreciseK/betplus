<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Engine\Caged\CagedEngine;
use App\Domain\Games\Engine\Caged\CagedTier;
use App\Domain\Games\Engine\Caged\Digest;
use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Tax\TaxEngine;
use App\Domain\Ticket\SendTicketReceiptSms;
use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\PoolDraw;
use App\Models\PoolEntry;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settles one Caged pari-mutuel pool: draws one shared escaped-bird count and pays
 * every entry whose target was met or beaten by it (threshold, not exact-match —
 * preserves Caged's existing "at least my target" semantics), proportional to stake.
 * See docs/superpowers/specs/2026-09-15-pari-mutuel-pool-design.md §6.
 */
final class CagedPoolSettlementService
{
    private const GAME_CODE = 'CAGED';

    public function __construct(
        private readonly SeedIssuer $seedIssuer,
        private readonly CagedEngine $engine,
        private readonly PrizeTableResolver $prizeTableResolver,
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
        $escapedBirds = $this->drawEscapedBirds($seed->seedHex);

        $entries = PoolEntry::where('poolDrawId', $pool->id)->get();
        $rakeKobo = intdiv($pool->grossStakedKobo * $params->rakeBps, 10_000);

        if ($pool->grossStakedKobo > 0) {
            $this->wallet->closePariMutuelPool($pool->grossStakedKobo, $rakeKobo, 'pool_draw', $pool->id);
        }

        $netPoolKobo = ($pool->grossStakedKobo - $rakeKobo) + $pool->rolloverInKobo;

        // Threshold win, not exact-match: every entry whose target was met or beaten.
        $winners = $entries->filter(fn (PoolEntry $entry) => ((int) $entry->predictionJson[0]) <= $escapedBirds);
        $winnerStakes = $winners->pluck('stakeKobo', 'id')->all();
        $split = $this->calculator->split($netPoolKobo, $winnerStakes);

        foreach ($entries as $entry) {
            $won = array_key_exists($entry->id, $split['payouts']);
            $payoutKobo = $split['payouts'][$entry->id] ?? 0;
            $this->settleEntry($entry, $won, $payoutKobo, $escapedBirds, $seed->seedHex);
        }

        if ($winners->isNotEmpty() && $split['remainderKobo'] > 0) {
            $this->wallet->sweepPariMutuelRemainder($split['remainderKobo'], 'pool_draw', $pool->id);
        }

        $rolloverOutKobo = $winners->isEmpty() ? $netPoolKobo : 0;

        $pool->update([
            'status' => 'settled',
            'drawnOutcomeJson' => ['escapedBirds' => $escapedBirds],
            'fairnessSeedId' => $seed->id,
            'rakeKobo' => $rakeKobo,
            'netPoolKobo' => $netPoolKobo,
            'drawnAt' => now(),
            'settledAt' => now(),
        ]);

        $this->poolDraws->openPool($pool->gameCode, $pool->poolKey, $params->poolWindowMinutes, $rolloverOutKobo, null, $pool->closesAt);

        return true;
    }

    /**
     * Reuses CagedEngine::resolve() purely for its escapedBirds computation — that
     * value doesn't actually depend on the targetBirds argument (only won/
     * grossPrizeKobo do), so a dummy target=1/stake=0 call against the real,
     * currently-published tiers yields the same shared draw a genuine per-ticket
     * resolve would have, with no new engine code needed.
     */
    private function drawEscapedBirds(string $seedHex): int
    {
        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, (string) config('jurisdiction.stub_state_code'));
        if ($prizeTable === null) {
            throw new RuntimeException('No published Caged prize table to draw a pool outcome against.');
        }

        $tiers = $prizeTable->tiers
            ->map(fn ($tier) => new CagedTier(
                (int) $tier->positions,
                (int) $tier->probabilityNumerator,
                (int) $tier->probabilityDenominator,
                (int) $tier->multiplierHundredths,
            ))
            ->all();

        return $this->engine->resolve($seedHex, 1, 0, $tiers)->escapedBirds;
    }

    private function settleEntry(PoolEntry $entry, bool $won, int $payoutKobo, int $escapedBirds, string $seedHex): void
    {
        DB::transaction(function () use ($entry, $won, $payoutKobo, $escapedBirds, $seedHex) {
            $ticket = Ticket::where('id', $entry->ticketId)->where('status', 'PENDING_DRAW')->lockForUpdate()->first();
            if ($ticket === null) {
                return; // already settled by a concurrent run
            }

            $player = Player::findOrFail($entry->playerId);
            $targetBirds = (int) $entry->predictionJson[0];

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
                'resultJson' => ['escaped_birds' => $escapedBirds, 'target_birds' => $targetBirds],
                'won' => $won,
                'grossPrizeKobo' => $payoutKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => Digest::of($seedHex, $targetBirds, $escapedBirds),
            ]);

            $entry->update(['won' => $won, 'payoutKobo' => $payoutKobo]);
            $ticket->update(['status' => 'SETTLED']);

            $this->dailyLedger->recordSettlement(self::GAME_CODE, $entry->stakeKobo, $payoutKobo);

            $this->sms->send($player, $ticket->fresh('outcome'));
        });
    }
}
