<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MakerChecker\MakerCheckerService;
use App\Domain\Promotions\Jobs\MonthlyDrawTicketAggregationJob;
use App\Domain\Promotions\PromotionManagerService;
use App\Domain\Wallet\WalletService;
use App\Models\InstitutionUser;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MonthlyDrawPool;
use App\Models\Player;
use App\Models\PlayerPromotionalMilestone;
use App\Models\PlayerWallet;
use App\Models\PromotionalBoostClaim;
use App\Models\PromotionalCampaign;
use App\Models\ReviewableChange;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionalOffersTest extends TestCase
{
    use RefreshDatabase;

    private function player(string $phone = '+2348031234567'): Player
    {
        return Player::create(['msisdn' => $phone, 'registeredName' => 'Chidi Anagonye', 'registrationChannel' => 'web']);
    }

    private function institutionUser(string $role = 'game_ops'): InstitutionUser
    {
        return InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Test Operator',
            'passwordHash' => password_hash('secret', PASSWORD_BCRYPT),
            'role' => $role,
            'status' => 'active',
            'mfaSecretEncrypted' => 'dummy_encrypted_secret',
            'mfaConfirmedAt' => now(),
        ]);
    }

    // ── 1. DUAL-LEDGER BONUS WALLET TESTS ─────────────────────────────────────

    public function test_bonus_balance_credit_and_priority_stake_reservation(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);

        // Player has ₦500 Real Play (50,000 kobo) and ₦200 Bonus Play (20,000 kobo)
        $walletService->creditPlayBalanceFromOpay($player, 50_000, 'collection', 1);
        $walletService->creditBonusPlayBalance($player, 20_000, 'promo', 1);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(50_000, $wallet->playBalanceKobo);
        $this->assertSame(20_000, $wallet->bonusBalanceKobo);

        // Stake ₦100 (10,000 kobo) -> Should consume bonus first!
        $walletService->reserveStake($player, 10_000, 'ticket', 101);

        $wallet->refresh();
        $this->assertSame(50_000, $wallet->playBalanceKobo); // Untouched
        $this->assertSame(10_000, $wallet->bonusBalanceKobo); // 20k - 10k = 10k

        $this->assertSame(10_000, $walletService->bonusStakeFor('ticket', 101));

        // Next stake ₦250 (25,000 kobo) -> Consumes remaining 10,000 bonus, then 15,000 real play
        $walletService->reserveStake($player, 25_000, 'ticket', 102);

        $wallet->refresh();
        $this->assertSame(0, $wallet->bonusBalanceKobo);
        $this->assertSame(35_000, $wallet->playBalanceKobo); // 50k - 15k = 35k

        $this->assertSame(10_000, $walletService->bonusStakeFor('ticket', 102));
    }

    public function test_settle_loss_with_bonus_reclaims_to_bonus_expense(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);

        $walletService->creditBonusPlayBalance($player, 10_000, 'promo', 1);
        $walletService->reserveStake($player, 10_000, 'ticket', 201);

        // Settle Loss
        $walletService->settleLoss(10_000, 'ticket', 201);

        // Verify ledger entries
        $bonusExpenseAccount = LedgerAccount::where('type', 'BONUS_EXPENSE')->first();
        $this->assertNotNull($bonusExpenseAccount);

        $reclaimed = (int) LedgerEntry::where('accountId', $bonusExpenseAccount->id)
            ->where('direction', 'credit')
            ->sum('amountKobo');

        $this->assertSame(10_000, $reclaimed);
    }

    public function test_settle_win_with_bonus_applies_1x_playthrough_net_profit(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);

        // Player has ₦100 bonus (10,000 kobo)
        $walletService->creditBonusPlayBalance($player, 10_000, 'promo', 1);
        $walletService->reserveStake($player, 10_000, 'ticket', 301);

        // Wins 2x = 20,000 kobo gross prize, 0 tax.
        // Net profit is 20k - 10k bonus = 10,000 kobo credited to winnings!
        $walletService->settleWin($player, 10_000, 20_000, 0, 20_000, 'ticket', 301);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(10_000, $wallet->winningsBalanceKobo); // Only net winnings
        $this->assertSame(0, $wallet->bonusBalanceKobo);

        // Bonus principal was returned to BONUS_EXPENSE
        $bonusExpenseAccount = LedgerAccount::where('type', 'BONUS_EXPENSE')->first();
        $this->assertSame(10_000, (int) LedgerEntry::where('accountId', $bonusExpenseAccount->id)->where('direction', 'credit')->sum('amountKobo'));
    }

    // ── 2. WEEKEND DOUBLE ODDS BOOST TESTS ────────────────────────────────────

    public function test_weekend_boost_applies_within_budget_and_caps(): void
    {
        $player = $this->player();
        $promoService = app(PromotionManagerService::class);

        // Enable campaign with forceActive for test environment
        PromotionalCampaign::create([
            'campaignKey' => 'weekend_double_odds',
            'name' => 'Weekend Double Odds',
            'status' => 'ENABLED',
            'rulesJson' => [
                'maxStakeKobo' => 100_00,       // max ₦100 stake
                'maxBonusKobo' => 1000_00,      // max ₦1,000 bonus
                'totalBudgetKobo' => 500000_00, // ₦500,000
                'forceActive' => true,
            ],
        ]);

        $boostBonusKobo = $promoService->evaluateWeekendBoost($player, 100_00, 200_00, 'BLACKRED', null);
        $this->assertSame(200_00, $boostBonusKobo);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(200_00, $wallet->winningsBalanceKobo);

        // Second win should NOT be eligible (1 claim per user per weekend)
        $secondClaim = $promoService->evaluateWeekendBoost($player, 100_00, 200_00, 'BLACKRED', null);
        $this->assertNull($secondClaim);

        $this->assertSame(1, PromotionalBoostClaim::where('playerId', $player->id)->count());
    }

    // ── 3. VELOCITY MILESTONE 30-ROUND TESTS ──────────────────────────────────

    public function test_velocity_30_rounds_awards_bonus_play_credit(): void
    {
        $player = $this->player();
        $promoService = app(PromotionManagerService::class);

        PromotionalCampaign::create([
            'campaignKey' => 'velocity_bonus',
            'name' => 'Velocity 30 Rounds',
            'status' => 'ENABLED',
            'rulesJson' => [
                'targetRounds' => 30,
                'bonusAmountKobo' => 500_00, // ₦500
                'expiryDays' => 7,
            ],
        ]);

        // Simulate 29 rounds
        for ($i = 1; $i <= 29; $i++) {
            $awarded = $promoService->recordRoundAndCheckMilestone($player, 'BLACKRED');
            $this->assertNull($awarded);
        }

        $milestone = PlayerPromotionalMilestone::where('playerId', $player->id)->first();
        $this->assertSame(29, $milestone->roundsCompleted);
        $this->assertFalse($milestone->isAwarded);

        // 30th round triggers reward!
        $awarded = $promoService->recordRoundAndCheckMilestone($player, 'BLACKRED');
        $this->assertSame(500_00, $awarded);

        $milestone->refresh();
        $this->assertTrue($milestone->isAwarded);
        $this->assertSame(30, $milestone->roundsCompleted);

        // Verify player wallet received bonus
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(500_00, $wallet->bonusBalanceKobo);

        // 31st round does not award again
        $awarded31 = $promoService->recordRoundAndCheckMilestone($player, 'BLACKRED');
        $this->assertNull($awarded31);
    }

    // ── 4. MONTHLY VIP ACCUMULATOR DRAW TESTS ─────────────────────────────────

    public function test_monthly_draw_calculates_1_pct_pool_and_awards_tickets(): void
    {
        $walletService = app(WalletService::class);

        $playerA = $this->player('+2348031111111');
        $playerB = $this->player('+2348032222222');

        // Player A stakes ₦40,000 (4,000,000 kobo) -> 2 tickets
        // Player B stakes ₦20,000 (2,000,000 kobo) -> 1 ticket
        // Total platform turnover = 6,000,000 kobo.
        $walletService->creditPlayBalanceFromOpay($playerA, 40000_00, 'collection', 1);
        $walletService->creditPlayBalanceFromOpay($playerB, 20000_00, 'collection', 2);

        $walletService->reserveStake($playerA, 40000_00, 'ticket', 501);
        $walletService->reserveStake($playerB, 20000_00, 'ticket', 502);

        $period = Carbon::now('Africa/Accra')->format('Y-m');

        // Run monthly draw job
        $job = new MonthlyDrawTicketAggregationJob($period);
        $job->handle($walletService, app(\App\Domain\Fairness\SeedIssuer::class));

        $pool = MonthlyDrawPool::where('monthPeriod', $period)->first();
        $this->assertNotNull($pool);
        $this->assertSame('DISBURSED', $pool->status);
        $this->assertSame(60000_00, $pool->totalTurnoverKobo);
        $this->assertSame(600_00, $pool->allocatedPrizePoolKobo); // 1% of 60,000 = 600 kobo
        $this->assertSame(3, $pool->totalTicketsIssued); // 2 + 1 = 3 tickets

        // Check entries
        $this->assertSame(2, $pool->entries()->where('playerId', $playerA->id)->value('ticketCount'));
        $this->assertSame(1, $pool->entries()->where('playerId', $playerB->id)->value('ticketCount'));
    }

    // ── 5. MAKER-CHECKER ADMIN GOVERNANCE TESTS ───────────────────────────────

    public function test_maker_checker_promo_configuration_workflow(): void
    {
        $makerChecker = app(MakerCheckerService::class);
        $maker = $this->institutionUser('game_ops');
        $checker = $this->institutionUser('compliance');

        // Maker proposes to update weekend double odds
        $change = $makerChecker->propose(
            'promotional_config_publish',
            [
                'campaign_key' => 'weekend_double_odds',
                'status' => 'ENABLED',
                'rules' => ['totalBudgetKobo' => 800000_00],
            ],
            null,
            $maker,
            'Enable weekend double odds for launch'
        );

        $this->assertSame('AWAITING_APPROVAL', $change->status);

        // Maker cannot approve own change
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A maker may not approve their own change.');
        $makerChecker->approve($change, $maker);
    }

    public function test_checker_approval_applies_campaign_config(): void
    {
        $makerChecker = app(MakerCheckerService::class);
        $maker = $this->institutionUser('game_ops');
        $checker = $this->institutionUser('compliance');

        $change = $makerChecker->propose(
            'promotional_config_publish',
            [
                'campaign_key' => 'weekend_double_odds',
                'status' => 'ENABLED',
                'rules' => ['totalBudgetKobo' => 800000_00],
            ],
            null,
            $maker,
            'Enable weekend double odds for launch'
        );

        // Checker approves
        $makerChecker->approve($change, $checker);

        $campaign = PromotionalCampaign::where('campaignKey', 'weekend_double_odds')->first();
        $this->assertNotNull($campaign);
        $this->assertSame('ENABLED', $campaign->status);
        $this->assertSame(800000_00, $campaign->rulesJson['totalBudgetKobo']);
    }
}
