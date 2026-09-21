<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Domain\Games\Economics\PoolDrawService;
use App\Domain\Games\Economics\EconomicsModelStrategy;
use App\Domain\Games\Engine\Caged\CagedEngine;
use App\Domain\Games\Engine\Caged\CagedTier;
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
 * Caged's counterpart to Domain/Ticket/CreateTicket — same two-phase pipeline
 * (eligibility/resolution with no locks held, then one short commit
 * transaction), same game-agnostic wallet/tax/RG/registry/velocity/analytics
 * machinery. The only real difference from BlackRed's CreateTicket is the
 * engine call: a single int targetBirds (1-5) instead of a B/R prediction
 * list, and win = escapedBirds >= targetBirds rather than an exact-match
 * comparison. Uses the generic PrizeTableResolver directly — Caged's
 * PrizeTable relation (tiers()) is the same generic one BlackRed uses, so no
 * dedicated resolver class is needed (unlike Heritage's).
 */
final class CreateCagedTicket
{
    private const REQUIRED_KYC_TIER = 1;
    private const GAME_CODE = 'CAGED';
    private readonly PromotionManagerService $promoManagerInstance;

    public function __construct(
        private readonly AttributionService $attribution,
        private readonly SeedIssuer $seedIssuer,
        private readonly PrizeTableResolver $prizeTableResolver,
        private readonly CagedEngine $engine,
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

    public function create(Player $player, int $targetBirds, int $stakeKobo, string $idempotencyKey): Ticket
    {
        $existing = Ticket::where('idempotencyKey', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // ── ELIGIBILITY AND RESOLUTION — no database locks held ──
        $game = GameRegistry::where('gameCode', self::GAME_CODE)->first();
        if ($game === null || $game->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged is temporarily undergoing maintenance. Please check back shortly.');
        }

        if ($targetBirds < 1 || $targetBirds > 5) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Target must be between 1 and 5 birds.');
        }

        $this->assertKycTier($player);
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);

        $attribution = $this->attribution->attribute($player, $game);

        if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
            $minNaira = number_format($game->minStakeKobo / 100, 0);
            $maxNaira = number_format($game->maxStakeKobo / 100, 0);
            throw new TicketEligibilityException('GAME_UNAVAILABLE', "Stake must be between ₦{$minNaira} and ₦{$maxNaira}.");
        }
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);

        $economicsConfig = $this->economicsConfigResolver->resolveFor(self::GAME_CODE);
        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($economicsConfig);
        $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());

        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, $attribution['stateCode']);
        if ($prizeTable === null || $prizeTable->tiers->firstWhere('positions', $targetBirds) === null) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged prize table is temporarily unavailable. Please try again shortly.');
        }

        if ($economicsConfig?->activeModel === 'PARI_MUTUEL_POOL') {
            return $this->joinPool($player, $targetBirds, $stakeKobo, $idempotencyKey, $attribution, $prizeTable, $economicsStrategy, PariMutuelPoolParams::fromArray($economicsConfig->paramsJson));
        }

        $seed = $this->seedIssuer->issue();
        $engineTiers = $prizeTable->tiers
            ->map(fn ($tier) => new CagedTier(
                (int) $tier->positions,
                (int) $tier->probabilityNumerator,
                (int) $tier->probabilityDenominator,
                (int) $tier->multiplierHundredths,
            ))
            ->all();
        // The publication gate keeps a malformed tier set from ever reaching a live
        // prize table, so CagedEngine::validateTiers() throwing here is not expected
        // in practice — but if it ever did, an uncaught InvalidArgumentException would
        // surface as a 500 instead of the same clean "unavailable" response the missing-
        // tier check just above gives, so it is treated identically.
        try {
            $engineResult = $this->engine->resolve($seed->seedHex, $targetBirds, $stakeKobo, $engineTiers);
        } catch (\InvalidArgumentException $e) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged has no published prize table for this state.');
        }

        $withholding = $engineResult->won ? $this->tax->withhold($engineResult->grossPrizeKobo, $player) : null;

        // ── COMMITMENT — single transaction, locks held briefly ──
        $ticket = DB::transaction(function () use (
            $player, $targetBirds, $stakeKobo, $idempotencyKey, $attribution, $seed, $engineResult, $withholding, $prizeTable, $economicsStrategy,
        ) {
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);

            $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());

            $ticket = Ticket::create([
                'reference' => (string) Str::ulid(),
                'playerId' => $player->id,
                'gameCode' => self::GAME_CODE,
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => [$targetBirds],
                'positions' => $targetBirds,
                'prizeTableVersion' => $prizeTable->version,
                'rngSeedRef' => $seed->id,
                'rngAlgorithm' => $seed->algorithm,
                'engineVersion' => $engineResult->engineVersion,
                'status' => 'CREATED',
            ]);

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
                'resultJson' => ['escaped_birds' => $engineResult->escapedBirds, 'target_birds' => $targetBirds],
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
                    $player, $stakeKobo, $engineResult->grossPrizeKobo,
                    $taxWithheldKobo, $netCreditKobo, 'ticket', $ticket->id, $attribution['stateCode'],
                );

                // Offer 1: Weekend Double Win Boost (Marketing Subvention)
                $this->promoManagerInstance->evaluateWeekendBoost(
                    $player, $stakeKobo, $engineResult->grossPrizeKobo, self::GAME_CODE, $ticket->id,
                );
            } else {
                $this->wallet->settleLoss($stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);
            }

            $this->dailyLedger->recordSettlement(self::GAME_CODE, $stakeKobo, $engineResult->won ? $engineResult->grossPrizeKobo : 0);

            $ticket->update(['status' => 'SETTLED']);

            return $ticket;
        });

        // Offer 2: Monthly VIP Draw turnover tracking
        $this->promoManagerInstance->recordTurnover($player, $stakeKobo);

        // Offer 3: Velocity Milestone Bonus Wallet (30+ rounds)
        $this->promoManagerInstance->recordRoundAndCheckMilestone($player, self::GAME_CODE);

        if ($engineResult->won) {
            DispatchPrizePayoutJob::dispatch($ticket->id);
        }

        $this->velocity->evaluateAfterTicket($player, self::GAME_CODE, $stakeKobo);

        $this->analytics->record('ticket_purchased', $player, 'web', gameCode: self::GAME_CODE, stateCode: $attribution['stateCode'], properties: [
            'stake_kobo' => $stakeKobo,
            'won' => $engineResult->won,
        ]);

        return $ticket;
    }

    /**
     * ponytail: same turnover/velocity/analytics skip as BlackRed's joinPool() —
     * see that class's doc comment.
     *
     * @param array{stateCode: string, confidence: float, rulesetVersion: string} $attribution
     */
    private function joinPool(
        Player $player,
        int $targetBirds,
        int $stakeKobo,
        string $idempotencyKey,
        array $attribution,
        PrizeTable $prizeTable,
        EconomicsModelStrategy $economicsStrategy,
        PariMutuelPoolParams $poolParams,
    ): Ticket {
        // Caged runs one pool per window regardless of target (poolKey null) —
        // unlike BlackRed, whose pick-length changes the RNG's shape entirely, a
        // target here is just a threshold read against one shared escaped-bird draw.
        $seed = $this->seedIssuer->issue();

        return DB::transaction(function () use ($player, $targetBirds, $stakeKobo, $idempotencyKey, $attribution, $prizeTable, $economicsStrategy, $poolParams, $seed) {
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
            $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());

            $pool = $this->poolDraws->currentOrNextPool(self::GAME_CODE, null, $poolParams->poolWindowMinutes);

            $ticket = Ticket::create([
                'reference' => (string) Str::ulid(),
                'playerId' => $player->id,
                'gameCode' => self::GAME_CODE,
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => [$targetBirds],
                'positions' => $targetBirds,
                'prizeTableVersion' => $prizeTable->version,
                'rngSeedRef' => $seed->id,
                'rngAlgorithm' => $seed->algorithm,
                'engineVersion' => CagedEngine::VERSION,
                'status' => 'PENDING_DRAW',
            ]);

            $this->wallet->reserveStake($player, $stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);

            PoolEntry::create([
                'poolDrawId' => $pool->id,
                'ticketId' => $ticket->id,
                'playerId' => $player->id,
                'predictionJson' => [$targetBirds],
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
