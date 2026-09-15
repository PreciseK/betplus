<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\PlayerPromotionalMilestone;
use App\Models\PromotionalBoostClaim;
use App\Models\PromotionalCampaign;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class PromotionManagerService
{
    private const ACCRA_TZ = 'Africa/Accra';

    public function __construct(
        private readonly WalletService $wallet,
    ) {
    }

    public function isCampaignActive(string $campaignKey): bool
    {
        $config = $this->getCampaignConfig($campaignKey);

        return $config !== null && ($config['status'] ?? 'DISABLED') === 'ENABLED';
    }

    /** @return array<string, mixed>|null */
    public function getCampaignConfig(string $campaignKey): ?array
    {
        return Cache::remember("promo:config:{$campaignKey}", 60, function () use ($campaignKey) {
            $campaign = PromotionalCampaign::where('campaignKey', $campaignKey)->first();
            if ($campaign === null) {
                return null;
            }

            return [
                'campaignKey' => $campaign->campaignKey,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'rulesJson' => $campaign->rulesJson,
                'version' => $campaign->version,
            ];
        });
    }

    public function clearCampaignCache(string $campaignKey): void
    {
        Cache::forget("promo:config:{$campaignKey}");
    }

    /**
     * Offer 1: Weekend Double Win Boost (Marketing Subvention).
     * If eligible, credits bonus winnings from marketing budget without altering engine odds.
     */
    public function evaluateWeekendBoost(Player $player, int $stakeKobo, int $grossPrizeKobo, string $gameCode, ?int $ticketId = null): ?int
    {
        if (!$this->isCampaignActive('weekend_double_odds')) {
            return null;
        }

        $config = $this->getCampaignConfig('weekend_double_odds');
        $rules = $config['rulesJson'] ?? [];

        // Time window check: default to Saturday 00:00 to Sunday 23:59 WAT
        $now = Carbon::now(self::ACCRA_TZ);
        $isWeekend = $now->isSaturday() || $now->isSunday();
        if (!$isWeekend && empty($rules['forceActive'])) {
            return null;
        }

        // Stake cap: default max ₦100 (10,000 kobo)
        $maxStakeKobo = (int) ($rules['maxStakeKobo'] ?? 100_00);
        if ($stakeKobo > $maxStakeKobo) {
            return null;
        }

        // Campaign period code for this weekend, e.g. 'WEEKEND_2026_W37'
        $campaignCode = 'WEEKEND_' . $now->year . '_W' . str_pad((string) $now->weekOfYear, 2, '0', STR_PAD_LEFT);

        // Player restriction: 1 claim per weekend
        $alreadyClaimed = PromotionalBoostClaim::where('campaignCode', $campaignCode)
            ->where('playerId', $player->id)
            ->exists();
        if ($alreadyClaimed) {
            return null;
        }

        // Boost bonus calculation: double win means +1x gross prize, capped at maxBonusKobo (default ₦1,000 = 100,000 kobo)
        $maxBonusKobo = (int) ($rules['maxBonusKobo'] ?? 1000_00);
        $boostBonusKobo = min($grossPrizeKobo, $maxBonusKobo);
        if ($boostBonusKobo <= 0) {
            return null;
        }

        // Budget ceiling check (default ₦500,000 = 50,000,000 kobo)
        $totalBudgetKobo = (int) ($rules['totalBudgetKobo'] ?? 500000_00);
        $spentBudgetKobo = (int) PromotionalBoostClaim::where('campaignCode', $campaignCode)->sum('boostBonusKobo');
        if (($spentBudgetKobo + $boostBonusKobo) > $totalBudgetKobo) {
            return null;
        }

        return DB::transaction(function () use ($player, $campaignCode, $ticketId, $gameCode, $grossPrizeKobo, $boostBonusKobo) {
            PromotionalBoostClaim::create([
                'campaignCode' => $campaignCode,
                'playerId' => $player->id,
                'ticketId' => $ticketId,
                'gameCode' => $gameCode,
                'originalPrizeKobo' => $grossPrizeKobo,
                'boostBonusKobo' => $boostBonusKobo,
                'claimedAt' => Carbon::now(self::ACCRA_TZ),
            ]);

            $this->wallet->settleMarketingBoost($player, $boostBonusKobo, 'ticket', $ticketId ?? 0);

            return $boostBonusKobo;
        });
    }

    /**
     * Offer 2: Monthly Stake Accumulator (Turnover tracking).
     */
    public function recordTurnover(Player $player, int $stakeKobo): void
    {
        if (!$this->isCampaignActive('monthly_vip_draw')) {
            return;
        }

        $monthKey = Carbon::now(self::ACCRA_TZ)->format('Y_m');

        try {
            Redis::incrby("turnover:{$player->id}:{$monthKey}", $stakeKobo);
            Redis::incrby("platform_turnover:{$monthKey}", $stakeKobo);
        } catch (\Throwable) {
            // Redis failure should not block ticket placement
        }
    }

    /**
     * Offer 3: Velocity Milestone Bonus Wallet (30+ Rounds Played).
     * Awards ₦500 bonus play-credit upon reaching milestone.
     */
    public function recordRoundAndCheckMilestone(Player $player, string $gameCode): ?int
    {
        if (!$this->isCampaignActive('velocity_bonus')) {
            return null;
        }

        $config = $this->getCampaignConfig('velocity_bonus');
        $rules = $config['rulesJson'] ?? [];

        $targetRounds = (int) ($rules['targetRounds'] ?? 30);
        $bonusAmountKobo = (int) ($rules['bonusAmountKobo'] ?? 500_00);
        $expiryDays = (int) ($rules['expiryDays'] ?? 7);

        // Weekly or monthly milestone window (defaults to calendar month)
        $now = Carbon::now(self::ACCRA_TZ);
        $windowStart = $now->copy()->startOfMonth();
        $windowEnd = $now->copy()->endOfMonth();
        $campaignCode = 'VELOCITY_' . $windowStart->format('Y_m');

        return DB::transaction(function () use ($player, $campaignCode, $targetRounds, $bonusAmountKobo, $expiryDays, $windowStart, $windowEnd) {
            $milestone = PlayerPromotionalMilestone::firstOrCreate(
                [
                    'playerId' => $player->id,
                    'campaignCode' => $campaignCode,
                    'windowStart' => $windowStart,
                ],
                [
                    'targetRounds' => $targetRounds,
                    'roundsCompleted' => 0,
                    'windowEnd' => $windowEnd,
                    'isAwarded' => false,
                ]
            );

            $milestone->increment('roundsCompleted');

            if ($milestone->roundsCompleted >= $milestone->targetRounds && !$milestone->isAwarded) {
                $milestone->update([
                    'isAwarded' => true,
                    'bonusAwardedKobo' => $bonusAmountKobo,
                    'awardedAt' => Carbon::now(self::ACCRA_TZ),
                    'bonusExpiresAt' => Carbon::now(self::ACCRA_TZ)->addDays($expiryDays),
                ]);

                $this->wallet->creditBonusPlayBalance($player, $bonusAmountKobo, 'milestone_reward', $milestone->id);

                return $bonusAmountKobo;
            }

            return null;
        });
    }
}
