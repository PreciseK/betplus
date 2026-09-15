<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\Controllers; // or App\Http\Controllers\BackOffice

namespace App\Http\Controllers\BackOffice;

use App\Domain\Promotions\Jobs\MonthlyDrawTicketAggregationJob;
use App\Domain\Promotions\PromotionManagerService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\MonthlyDrawEntry;
use App\Models\MonthlyDrawPool;
use App\Models\PlayerPromotionalMilestone;
use App\Models\PromotionalBoostClaim;
use App\Models\PromotionalCampaign;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionalCampaignController extends Controller
{
    private const DEFAULT_CAMPAIGNS = [
        'weekend_double_odds' => [
            'name' => 'Weekend Double Odds Boost',
            'description' => 'Marketing-subsidized double winnings boost on 1st winning bet under ₦100 (max ₦1,000 bonus).',
            'status' => 'DISABLED',
            'rules' => [
                'maxStakeKobo' => 100_00,       // ₦100
                'maxBonusKobo' => 1000_00,      // ₦1,000
                'totalBudgetKobo' => 500000_00, // ₦500,000
                'forceActive' => false,
            ],
        ],
        'monthly_vip_draw' => [
            'name' => 'Monthly VIP Draw Pool',
            'description' => 'Promotional pool funded by 1% turnover rake; 1 ticket per ₦20,000 staked.',
            'status' => 'ENABLED',
            'rules' => [
                'qualifyingStakeKobo' => 20000_00, // ₦20,000
                'rakeBasisPoints' => 100,           // 1.00%
                'prizeDistribution' => [50, 30, 20],
            ],
        ],
        'velocity_bonus' => [
            'name' => 'Velocity Milestone Bonus Wallet',
            'description' => 'Awards ₦500 non-withdrawable bonus play credit after 30 rounds played.',
            'status' => 'ENABLED',
            'rules' => [
                'targetRounds' => 30,
                'bonusAmountKobo' => 500_00, // ₦500
                'expiryDays' => 7,
            ],
        ],
    ];

    public function __construct(
        private readonly PromotionManagerService $promoManager
    ) {
    }

    /** GET /backoffice/v1/promotions */
    public function index(): JsonResponse
    {
        $campaigns = PromotionalCampaign::all()->keyBy('campaignKey');

        $result = [];
        foreach (self::DEFAULT_CAMPAIGNS as $key => $default) {
            $campaign = $campaigns->get($key);
            $rules = $campaign ? $campaign->rulesJson : $default['rules'];
            $status = $campaign ? $campaign->status : $default['status'];
            $version = $campaign ? $campaign->version : 1;

            $stats = $this->campaignStats($key, $rules);

            $result[] = [
                'campaign_key' => $key,
                'name' => $campaign->name ?? $default['name'],
                'description' => $campaign->description ?? $default['description'],
                'status' => $status,
                'version' => $version,
                'rules' => $rules,
                'stats' => $stats,
            ];
        }

        return response()->json(['promotions' => $result]);
    }

    /** GET /backoffice/v1/promotions/{key} */
    public function show(string $key): JsonResponse
    {
        $campaign = PromotionalCampaign::where('campaignKey', $key)->first();
        $default = self::DEFAULT_CAMPAIGNS[$key] ?? null;

        if ($campaign === null && $default === null) {
            return response()->json(['message' => "Campaign {$key} not found."], 404);
        }

        $rules = $campaign ? $campaign->rulesJson : $default['rules'];
        $stats = $this->campaignStats($key, $rules);

        return response()->json([
            'campaign_key' => $key,
            'name' => $campaign->name ?? $default['name'],
            'description' => $campaign->description ?? $default['description'],
            'status' => $campaign->status ?? $default['status'],
            'version' => $campaign->version ?? 1,
            'rules' => $rules,
            'stats' => $stats,
        ]);
    }

    /** POST /backoffice/v1/promotions/{key}/emergency-kill */
    public function emergencyKill(Request $request, string $key): JsonResponse
    {
        $campaign = PromotionalCampaign::firstOrCreate(
            ['campaignKey' => $key],
            [
                'name' => self::DEFAULT_CAMPAIGNS[$key]['name'] ?? $key,
                'rulesJson' => self::DEFAULT_CAMPAIGNS[$key]['rules'] ?? [],
            ]
        );

        $campaign->update([
            'status' => 'DISABLED',
            'version' => $campaign->version + 1,
            'lastUpdatedBy' => $this->institutionUser()->id,
        ]);

        $this->promoManager->clearCampaignCache($key);

        AuditLog::create([
            'actorType' => 'institution_user',
            'actorId' => $this->institutionUser()->id,
            'action' => 'PROMOTION_EMERGENCY_KILL',
            'targetType' => 'promotional_campaign',
            'targetId' => (string) $campaign->id,
            'stateCode' => null,
            'payload' => [
                'campaign_key' => $key,
                'justification' => $request->input('justification', 'Emergency kill triggered by admin.'),
            ],
        ]);

        return response()->json([
            'message' => "Promotion {$key} has been immediately disabled.",
            'campaign_key' => $key,
            'status' => 'DISABLED',
        ]);
    }

    /** GET /backoffice/v1/promotions/monthly-draws */
    public function monthlyDraws(): JsonResponse
    {
        $pools = MonthlyDrawPool::with('entries')
            ->orderByDesc('monthPeriod')
            ->get();

        return response()->json([
            'draw_pools' => $pools->map(fn (MonthlyDrawPool $p) => [
                'id' => $p->id,
                'month_period' => $p->monthPeriod,
                'status' => $p->status,
                'total_turnover_kobo' => $p->totalTurnoverKobo,
                'allocated_prize_pool_kobo' => $p->allocatedPrizePoolKobo,
                'total_tickets_issued' => $p->totalTicketsIssued,
                'qualifying_players_count' => $p->entries->count(),
                'drawn_at' => $p->drawnAt?->toIso8601String(),
                'winners' => $p->winnersJson,
            ]),
        ]);
    }

    /** POST /backoffice/v1/promotions/monthly-draws/trigger */
    public function triggerMonthlyDraw(Request $request): JsonResponse
    {
        $monthPeriod = $request->input('month_period'); // optional, e.g. '2026-08'

        MonthlyDrawTicketAggregationJob::dispatchSync($monthPeriod);

        return response()->json([
            'message' => "Monthly draw execution dispatched for period: " . ($monthPeriod ?? 'previous month'),
        ]);
    }

    /** @return array<string, mixed> */
    private function campaignStats(string $key, array $rules): array
    {
        return match ($key) {
            'weekend_double_odds' => [
                'claims_count' => PromotionalBoostClaim::count(),
                'total_boost_awarded_kobo' => (int) PromotionalBoostClaim::sum('boostBonusKobo'),
                'allocated_budget_kobo' => (int) ($rules['totalBudgetKobo'] ?? 500000_00),
            ],
            'monthly_vip_draw' => [
                'latest_pool_tickets' => (int) (MonthlyDrawPool::latest('id')->value('totalTicketsIssued') ?? 0),
                'total_pools_completed' => MonthlyDrawPool::where('status', 'DISBURSED')->count(),
            ],
            'velocity_bonus' => [
                'milestones_achieved' => PlayerPromotionalMilestone::where('isAwarded', true)->count(),
                'total_bonus_credited_kobo' => (int) PlayerPromotionalMilestone::where('isAwarded', true)->sum('bonusAwardedKobo'),
            ],
            default => [],
        };
    }

    private function institutionUser(): InstitutionUser
    {
        /** @var InstitutionUser $user */
        $user = request()->attributes->get('institutionUser');

        return $user;
    }
}
