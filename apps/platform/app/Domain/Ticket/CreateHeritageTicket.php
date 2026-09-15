<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Games\Heritage\HeritageEngineClient;
use App\Domain\Games\Heritage\HeritageEngineResult;
use App\Domain\Games\PrizeTable\HeritagePrizeTableResolver;
use App\Domain\Jurisdiction\AttributionService;
use App\Domain\Promotions\PromotionManagerService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\ResponsibleGaming\VelocityService;
use App\Domain\Tax\TaxEngine;
use App\Domain\Wallet\WalletService;
use App\Jobs\DispatchPrizePayoutJob;
use App\Jobs\SubmitSecondChanceEntryJob;
use App\Models\GameRegistry;
use App\Models\HeritageSecondChanceEntry;
use App\Models\Player;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 7.6 / REQ-HG-003 / REQ-TKT-002 — ticket creation pipeline for Heritage.
 */
final class CreateHeritageTicket
{
    private const REQUIRED_KYC_TIER = 1;
    private const GAME_CODE = 'HERITAGE';
    private readonly PromotionManagerService $promoManagerInstance;

    public function __construct(
        private readonly AttributionService $attribution,
        private readonly SeedIssuer $seedIssuer,
        private readonly HeritagePrizeTableResolver $prizeTableResolver,
        private readonly HeritageEngineClient $engine,
        private readonly WalletService $wallet,
        private readonly TaxEngine $tax,
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly GameDailyLedgerService $dailyLedger,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly VelocityService $velocity,
        private readonly AnalyticsEventRecorder $analytics,
        ?PromotionManagerService $promoManager = null,
    ) {
        $this->promoManagerInstance = $promoManager ?? app(PromotionManagerService::class);
    }

    /** @param list<int> $selectedPositions exactly 5 distinct positions, 0-8 (REQ-HG-003) */
    public function create(
        Player $player,
        array $selectedPositions,
        int $stakeKobo,
        string $idempotencyKey,
        ?string $tradition = null,
        ?string $leaderType = null,
    ): Ticket {
        $existing = Ticket::where('idempotencyKey', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // ── ELIGIBILITY AND RESOLUTION — no database locks held (mirrors CreateTicket) ──
        $game = GameRegistry::where('gameCode', self::GAME_CODE)->first();
        if ($game === null || $game->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Heritage is not currently available.');
        }

        if (count($selectedPositions) !== 5 || count(array_unique($selectedPositions)) !== 5) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Exactly 5 distinct positions must be selected (REQ-HG-003).');
        }
        foreach ($selectedPositions as $position) {
            if ($position < 0 || $position > 8) {
                throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Selected positions must be within 0-8.');
            }
        }

        $this->assertKycTier($player);
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);

        $attribution = $this->attribution->attribute($player, $game);

        if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', "Stake must be between {$game->minStakeKobo} and {$game->maxStakeKobo} kobo.");
        }
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);

        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($this->economicsConfigResolver->resolveFor(self::GAME_CODE));
        $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());

        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, $attribution['stateCode']);
        if ($prizeTable === null || $prizeTable->heritageTiers->isEmpty()) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Heritage has no published prize table for this state.');
        }

        // REQ-HG-050 — stored on the profile and snapshotted onto each ticket. A
        // per-purchase override updates the stored preference; omitting it falls back
        // to whatever's already on the profile, or a configured default for a
        // first-ever Heritage purchase.
        $tradition ??= $player->heritageTraditionCode ?? array_key_first((array) config('heritage.traditions'));
        $leaderType ??= $player->heritageLeaderType ?? 'king';
        if ($player->heritageTraditionCode !== $tradition || $player->heritageLeaderType !== $leaderType) {
            $player->forceFill(['heritageTraditionCode' => $tradition, 'heritageLeaderType' => $leaderType])->save();
        }

        $seed = $this->seedIssuer->issue();
        $ticketReference = (string) Str::ulid();
        $engineResult = $this->engine->resolve($ticketReference, $seed->seedHex, $stakeKobo, $prizeTable, $selectedPositions, $tradition, $leaderType);

        $withholding = $engineResult->won() ? $this->tax->withhold($engineResult->grossPrizeKobo, $player) : null;

        // Auto-fund via direct withdrawal from OPay balance for USSD if play balance <= 0 or insufficient
        if (str_starts_with($idempotencyKey, 'ussd-')) {
            $walletModel = $this->wallet->walletFor($player);
            $totalHeadroom = (int) $walletModel->playBalanceKobo + (int) $walletModel->bonusBalanceKobo;
            if ($walletModel->playBalanceKobo <= 0 || $totalHeadroom < $stakeKobo) {
                $shortfall = max($stakeKobo - $totalHeadroom, 0);
                $withdrawKobo = $shortfall > 0 ? $shortfall : $stakeKobo;
                app(\App\Domain\Wallet\FundingService::class)->directWithdrawFromOpay($player, $withdrawKobo, 'ussd-auto-' . $idempotencyKey);
            }
        }

        // ── COMMITMENT — single transaction, locks held briefly ──
        $ticket = DB::transaction(function () use (
            $player, $selectedPositions, $stakeKobo, $idempotencyKey, $attribution, $seed,
            $engineResult, $withholding, $prizeTable, $ticketReference, $economicsStrategy,
        ) {
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);

            $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());

            $ticket = Ticket::create([
                'reference' => $ticketReference,
                'playerId' => $player->id,
                'gameCode' => self::GAME_CODE,
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => $selectedPositions,
                'positions' => 5,
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
                'resultJson' => [
                    'board' => $engineResult->board,
                    'winning_positions' => $engineResult->winningPositions,
                    'selected_positions' => $engineResult->selectedPositions,
                    'match_count' => $engineResult->matchCount,
                    'outcome_tier' => $engineResult->outcomeTier,
                    'tradition' => $engineResult->tradition,
                    'leader_type' => $engineResult->leaderType,
                    'second_chance_stake_kobo' => $engineResult->secondChanceStakeKobo,
                ],
                'won' => $engineResult->won(),
                'grossPrizeKobo' => $engineResult->grossPrizeKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => $engineResult->digest,
            ]);

            if ($engineResult->won()) {
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

                if ($engineResult->outcomeTier === 'TIER_SECOND_CHANCE' && $engineResult->secondChanceStakeKobo > 0) {
                    $this->wallet->recordDrawEntryCost($engineResult->secondChanceStakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);

                    HeritageSecondChanceEntry::create([
                        'ticketId' => $ticket->id,
                        'playerId' => $player->id,
                        'selectedNumbers' => $this->winningNumbers($engineResult),
                        'entryStakeKobo' => $engineResult->secondChanceStakeKobo,
                        'status' => 'SECOND_CHANCE_PENDING',
                    ]);
                }
            }

            $this->dailyLedger->recordSettlement(self::GAME_CODE, $stakeKobo, $engineResult->won() ? $engineResult->grossPrizeKobo : 0);

            $ticket->update(['status' => 'SETTLED']);

            return $ticket;
        });

        // Offer 2: Monthly VIP Draw turnover tracking
        $this->promoManagerInstance->recordTurnover($player, $stakeKobo);

        // Offer 3: Velocity Milestone Bonus Wallet (30+ rounds)
        $this->promoManagerInstance->recordRoundAndCheckMilestone($player, self::GAME_CODE);

        // REQ-HG-034 — asynchronous, durable, never blocking settlement.
        if ($engineResult->outcomeTier === 'TIER_SECOND_CHANCE') {
            SubmitSecondChanceEntryJob::dispatch($ticket->id);
        }

        if ($engineResult->won()) {
            DispatchPrizePayoutJob::dispatch($ticket->id);
        }

        $this->velocity->evaluateAfterTicket($player, self::GAME_CODE, $stakeKobo);

        $this->analytics->record('ticket_purchased', $player, 'web', gameCode: self::GAME_CODE, stateCode: $attribution['stateCode'], properties: [
            'stake_kobo' => $stakeKobo,
            'won' => $engineResult->won(),
            'outcome_tier' => $engineResult->outcomeTier,
        ]);

        return $ticket;
    }

    /**
     * REQ-HG-030 — "the player's five selected numbers" entered into the draw are the
     * board NUMBERS at the player's chosen positions (what the player actually saw
     * revealed as their pick), not the raw position indices.
     *
     * @return list<int>
     */
    private function winningNumbers(HeritageEngineResult $result): array
    {
        return array_map(
            fn (int $position) => $result->board[$position],
            $result->selectedPositions,
        );
    }

    private function assertKycTier(Player $player): void
    {
        if ($player->kycTier < self::REQUIRED_KYC_TIER) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Account has not reached the KYC tier required to play.');
        }
    }
}
