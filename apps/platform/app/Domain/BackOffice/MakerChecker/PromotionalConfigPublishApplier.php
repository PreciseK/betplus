<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Promotions\PromotionManagerService;
use App\Models\PromotionalCampaign;
use App\Models\ReviewableChange;
use RuntimeException;

/**
 * Applies maker-checker approved changes to promotional campaign configurations.
 * Payload: {
 *   campaign_key: string,
 *   status?: string ('ENABLED' | 'DISABLED'),
 *   rules?: array<string, mixed>,
 *   name?: string
 * }
 */
final class PromotionalConfigPublishApplier implements ReviewableChangeApplier
{
    public function __construct(
        private readonly PromotionManagerService $promoManager,
    ) {
    }

    public function apply(ReviewableChange $change): void
    {
        $payload = $change->payload;
        $campaignKey = $payload['campaign_key'] ?? null;
        if (!is_string($campaignKey) || $campaignKey === '') {
            throw new RuntimeException('Missing or invalid campaign_key in reviewable change payload.');
        }

        $campaign = PromotionalCampaign::where('campaignKey', $campaignKey)->first();
        if ($campaign === null) {
            $campaign = new PromotionalCampaign([
                'campaignKey' => $campaignKey,
                'name' => $payload['name'] ?? ucfirst(str_replace('_', ' ', $campaignKey)),
                'rulesJson' => $payload['rules'] ?? [],
            ]);
        }

        if (isset($payload['status']) && in_array($payload['status'], ['ENABLED', 'DISABLED'], true)) {
            $campaign->status = $payload['status'];
        }

        if (isset($payload['rules']) && is_array($payload['rules'])) {
            $campaign->rulesJson = array_merge($campaign->rulesJson ?? [], $payload['rules']);
        }

        if (isset($payload['name']) && is_string($payload['name'])) {
            $campaign->name = $payload['name'];
        }

        $campaign->version = ((int) $campaign->version) + 1;
        $campaign->lastUpdatedBy = $change->checkerId;
        $campaign->save();

        $this->promoManager->clearCampaignCache($campaignKey);
    }
}
