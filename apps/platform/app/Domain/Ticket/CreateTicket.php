<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategy;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Domain\Games\Economics\PoolDrawService;
use App\Domain\Games\Engine\BlackRed\BlackRedEngine;
use App\Domain\Games\Engine\BlackRed\EngineTier;
use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Jurisdiction\AttributionService;
use App\Domain\Promotions\PromotionManagerService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\ResponsibleGaming\VelocityService;
use App\Domain\Tax\TaxEngine;
use App\Domain\Wallet\WalletService;
use App\Jobs\DispatchPrizePayoutJob;
use App\Models\GameRegistry;
use App\Models\Player;
use App\Models\PoolEntry;
use App\Models\PrizeTable;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §7.11 / REQ-TKT-002 — the two-phase ticket pipeline, orchestrating D-08's ordering:
 * eligibility and engine resolution with no database locks held, then a single short
 * commitment transaction. Settlement (tax + ledger credit) happens synchronously
 * inside that same request — see ticket migration's doc comment for why this build
 * doesn't defer settlement to a later reveal call, and therefore doesn't need
 * REQ-TKT-006's auto-settle-abandoned job (there's no unsettled window to abandon).
 *
 * RG limits, cool-off/self-exclusion and exclusion-registry are all real gates now
 * (Epic 5). Per REQ-TKT-002's own carve-out, jurisdiction/licence-footprint/registry
 * results are "stable over the window" and not re-asserted inside the commitment
 * transaction — only limits and protection status are (mirroring the KYC-tier
 * re-assertion already here), since those can change between eligibility and commit
 * on a concurrent request the same way Play Balance can.
 */
final class CreateTicket
{
    private const REQUIRED_KYC_TIER = 1;
    private readonly PromotionManagerService $promoManagerInstance;

    public function __construct(
        private readonly AttributionService $attribution,
        private readonly SeedIssuer $seedIssuer,
        private readonly PrizeTableResolver $prizeTableResolver,
        private readonly BlackRedEngine $engine,
        private readonly WalletService $wallet,
        private readonly TaxEngine $tax,
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly GameDailyLedgerService $dailyLedger,
        private readonly PoolDrawService $poolDraws,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly VelocityService $velocity,
        private readonly AnalyticsEventRecorder $analytics,
        ?PromotionManagerService $promoManager = null,
    ) {
        $this->promoManagerInstance = $promoManager ?? app(PromotionManagerService::class);
    }

    /**
     * @param list<'B'|'R'> $prediction
     */
    public function create(Player $player, array $prediction, int $stakeKobo, string $idempotencyKey): Ticket
    {
        // D-10 — a retry with the same key returns the existing ticket rather than
        // creating a second one.
        $existing = Ticket::where('idempotencyKey', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // ── ELIGIBILITY AND RESOLUTION — no database locks held (REQ-TKT-002 steps 1-7) ──
        $game = GameRegistry::where('gameCode', 'BLACKRED')->first();
        if ($game === null || $game->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'BlackRed is not currently available.');
        }

        $this->assertKycTier($player);
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);

        $attribution = $this->attribution->attribute($player, $game);

        $length = count($prediction);
        if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', "Stake must be between {$game->minStakeKobo} and {$game->maxStakeKobo} kobo.");
        }
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);

        $economicsConfig = $this->economicsConfigResolver->resolveFor('BLACKRED');
        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($economicsConfig);
        $economicsStrategy->assertAcceptable('BLACKRED', $stakeKobo, EconomicsContext::forTicket());

        $prizeTable = $this->prizeTableResolver->resolveFor('BLACKRED', $attribution['stateCode']);
        if ($prizeTable === null || $prizeTable->tiers->firstWhere('positions', $length) === null) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'BlackRed has no published prize table for this state.');
        }

        // Model 4 — no per-ticket RNG roll happens at all; the ticket joins a shared
        // pool and its fate is decided later, all at once, when the pool draws.
        if ($economicsConfig?->activeModel === 'PARI_MUTUEL_POOL') {
            return $this->joinPool($player, $prediction, $stakeKobo, $idempotencyKey, $attribution, $prizeTable, $economicsStrategy, PariMutuelPoolParams::fromArray($economicsConfig->paramsJson));
        }

        $seed = $this->seedIssuer->issue();
        $engineTiers = $prizeTable->tiers
            ->map(fn ($tier) => new EngineTier((int) $tier->positions, (int) $tier->multiplierHundredths))
            ->all();
        // Idempotent on ticket_id per REQ-TKT-011 in spirit: this build resolves once,
        // pre-transaction, and never re-resolves for the same idempotencyKey because
        // the check above already returned early on a replay.
        $engineResult = $this->engine->resolve($seed->seedHex, $prediction, $stakeKobo, $engineTiers);

        $withholding = $engineResult->won ? $this->tax->withhold($engineResult->grossPrizeKobo, $player) : null;

        // ── COMMITMENT — single transaction, locks held briefly (REQ-TKT-002 steps 8-11) ──
        $ticket = DB::transaction(function () use ($player, $prediction, $stakeKobo, $idempotencyKey, $attribution, $seed, $engineResult, $withholding, $length, $prizeTable, $economicsStrategy) {
            // REQ-TKT-012 — re-assert KYC tier, protection status and limits inside the
            // transaction; jurisdiction/registry are not re-asserted (see class doc).
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
            $economicsStrategy->assertAcceptable('BLACKRED', $stakeKobo, EconomicsContext::forTicket());

            $ticket = Ticket::create([
                'reference' => (string) Str::ulid(),
                'playerId' => $player->id,
                'gameCode' => 'BLACKRED',
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => $prediction,
                'positions' => $length,
                'prizeTableVersion' => $prizeTable->version,
                'rngSeedRef' => $seed->id,
                'rngAlgorithm' => $seed->algorithm,
                'engineVersion' => $engineResult->engineVersion,
                'status' => 'CREATED',
            ]);

            // Reserve the stake — this is where insufficient balance actually fails
            // (REQ-TKT-012's balance re-assertion), rolling back the ticket insert too.
            $this->wallet->reserveStake($player, $stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);

            if ($withholding !== null) {
                $taxWithheldKobo = $withholding->taxWithheldKobo;
                $netCreditKobo = $withholding->netCreditKobo;
                $taxRateBasisPoints = $withholding->rateBasisPoints;
                $taxBasisLabel = $withholding->basisLabel;
                $taxRulesetVersion = $withholding->rulesetVersion;
            } else {
                $taxWithheldKobo = 0;
                $netCreditKobo = 0;
                $taxRateBasisPoints = 0;
                $taxBasisLabel = '';
                $taxRulesetVersion = '';
            }

            TicketOutcome::create([
                'ticketId' => $ticket->id,
                'resultJson' => $engineResult->result,
                'won' => $engineResult->won,
                'grossPrizeKobo' => $engineResult->grossPrizeKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => $engineResult->digest,
            ]);

            if ($engineResult->won) {
                $this->wallet->settleWin(
                    $player,
                    $stakeKobo,
                    $engineResult->grossPrizeKobo,
                    $withholding->taxWithheldKobo,
                    $withholding->netCreditKobo,
                    'ticket',
                    $ticket->id,
                    $attribution['stateCode'],
                );

                // Offer 1: Weekend Double Win Boost (Marketing Subvention)
                $this->promoManagerInstance->evaluateWeekendBoost(
                    $player,
                    $stakeKobo,
                    $engineResult->grossPrizeKobo,
                    'BLACKRED',
                    $ticket->id
                );
            } else {
                $this->wallet->settleLoss($stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);
            }

            $this->dailyLedger->recordSettlement('BLACKRED', $stakeKobo, $engineResult->won ? $engineResult->grossPrizeKobo : 0);

            $ticket->update(['status' => 'SETTLED']);

            return $ticket;
        });

        // Offer 2: Monthly VIP Draw turnover tracking
        $this->promoManagerInstance->recordTurnover($player, $stakeKobo);

        // Offer 3: Velocity Milestone Bonus Wallet (30+ rounds)
        $this->promoManagerInstance->recordRoundAndCheckMilestone($player, 'BLACKRED');

        // Story 4.1 / D-09 — queued off the play path, and deliberately dispatched
        // AFTER the transaction above has committed, so a win that somehow rolls back
        // can never queue a payout for a ticket that doesn't exist.
        if ($engineResult->won) {
            DispatchPrizePayoutJob::dispatch($ticket->id);
        }

        // Story 5.7 — informational only, never gates the response the player just got.
        $this->velocity->evaluateAfterTicket($player, 'BLACKRED', $stakeKobo);

        // Story 6.10 — funnel step (first paid play) and the per-play event the
        // blended-RTP/GGR reporting in Domain/BackOffice/Reporting reads. stakeKobo is
        // a legitimate analytics property (a stake amount is not PII); msisdn/NIN
        // never are, and AnalyticsEventRecorder refuses those keys outright.
        $this->analytics->record('ticket_purchased', $player, 'web', gameCode: 'BLACKRED', stateCode: $attribution['stateCode'], properties: [
            'stake_kobo' => $stakeKobo,
            'won' => $engineResult->won,
        ]);

        return $ticket;
    }

    /**
     * ponytail: skips turnover/velocity/analytics tracking a pool-joining purchase
     * would otherwise fire — those are all about a resolved wager, and this one
     * isn't resolved yet. Add them at settlement time (PoolDrawSettlementService)
     * if pari-mutuel play needs to count toward the same promos/milestones instant
     * play does; genuinely unclear from the spec whether it should.
     *
     * @param list<'B'|'R'> $prediction
     * @param array{stateCode: string, confidence: float, rulesetVersion: string} $attribution
     */
    private function joinPool(
        Player $player,
        array $prediction,
        int $stakeKobo,
        string $idempotencyKey,
        array $attribution,
        PrizeTable $prizeTable,
        EconomicsModelStrategy $economicsStrategy,
        PariMutuelPoolParams $poolParams,
    ): Ticket {
        $length = count($prediction);

        // A ticket still gets its own provenance seed (existing NOT NULL rngSeedRef
        // column, unchanged schema) even though it isn't what decides this ticket's
        // outcome — the pool's own shared seed (poolDraw.fairnessSeedId, issued once
        // at settlement) is. Keeping every ticket's own seed column populated avoids
        // a migration for a value that's harmless, if unused, here.
        $seed = $this->seedIssuer->issue();

        return DB::transaction(function () use ($player, $prediction, $stakeKobo, $idempotencyKey, $attribution, $prizeTable, $economicsStrategy, $poolParams, $length, $seed) {
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
            $economicsStrategy->assertAcceptable('BLACKRED', $stakeKobo, EconomicsContext::forTicket());

            $pool = $this->poolDraws->currentOrNextPool('BLACKRED', $length, $poolParams->poolWindowMinutes);

            $ticket = Ticket::create([
                'reference' => (string) Str::ulid(),
                'playerId' => $player->id,
                'gameCode' => 'BLACKRED',
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => $prediction,
                'positions' => $length,
                'prizeTableVersion' => $prizeTable->version,
                'rngSeedRef' => $seed->id,
                'rngAlgorithm' => $seed->algorithm,
                'engineVersion' => BlackRedEngine::VERSION,
                'status' => 'PENDING_DRAW',
            ]);

            $this->wallet->reserveStake($player, $stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);

            PoolEntry::create([
                'poolDrawId' => $pool->id,
                'ticketId' => $ticket->id,
                'playerId' => $player->id,
                'predictionJson' => $prediction,
                'stakeKobo' => $stakeKobo,
            ]);

            $pool->increment('grossStakedKobo', $stakeKobo);

            return $ticket;
        });
    }

    private function assertKycTier(Player $player): void
    {
        if ($player->kycTier < self::REQUIRED_KYC_TIER) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Account has not reached the KYC tier required to play.');
        }
    }
}
