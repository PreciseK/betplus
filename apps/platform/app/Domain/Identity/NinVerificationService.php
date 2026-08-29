<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Identity\Vault\IdentityVaultService;
use App\Domain\Identity\Verification\IdentityVendor;
use App\Models\AuditLog;
use App\Models\KycRecord;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Story 1.9 — verify NIN and reach Tier 1 (REQ-ID-020..024).
 *
 * Scope boundary: this handles the parts a backend service can complete alone. It does
 * NOT enforce KYC_TIER_REQUIRED at play time (REQ-ID-021 — no ticket endpoint exists yet,
 * Epic 3) and does NOT process an under-18 refund or regulator report (REQ-ID-024 — no
 * wallet balance can exist pre-Tier-1 anyway, and reporting is Epic 10). Both correctly
 * consult player.kycTier / player.accountStatus once they exist.
 */
final class NinVerificationService
{
    private const MINIMUM_AGE_YEARS = 18;
    // REQ-ID-020 Tier 1 row. Hardcoded because the table itself is currently fixed, not
    // configurable — move to config/a rules table if tiers ever become adjustable.
    private const TIER_1_MONTHLY_DEPOSIT_LIMIT_KOBO = 20_000_000;

    public function __construct(
        private readonly IdentityVendor $vendor,
        private readonly IdentityVaultService $vault,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    /** @return array{status: string, monthly_deposit_limit_kobo?: int} */
    public function verify(Player $player, string $dateOfBirth, string $nin): array
    {
        $dob = CarbonImmutable::parse($dateOfBirth);

        if ($dob->age < self::MINIMUM_AGE_YEARS) {
            KycRecord::create([
                'playerId' => $player->id,
                'idType' => 'nin',
                'verificationMethod' => 'automated',
                'dateOfBirth' => $dob,
                'rejectionReason' => 'Under 18 at date of birth submitted.',
            ]);
            // Blocks play and deposit immediately (REQ-ID-024). Tier 0 permits neither
            // anyway, so there is never a balance to refund at this point.
            $player->forceFill(['accountStatus' => 'frozen'])->save();
            AuditLog::create([
                'actorType' => 'system',
                'action' => 'player.frozen.under_age',
                'targetTable' => 'player',
                'targetId' => $player->id,
                'reason' => 'Date of birth submitted at KYC indicates under 18.',
            ]);

            return ['status' => 'under_age'];
        }

        $result = $this->vendor->verifyNin($nin);

        if (!$result->verified) {
            KycRecord::create([
                'playerId' => $player->id,
                'idType' => 'nin',
                'verificationMethod' => 'automated',
                'dateOfBirth' => $dob,
                'rejectionReason' => $result->rejectionReason ?? 'NIN could not be verified.',
            ]);

            return ['status' => 'nin_not_verified'];
        }

        $token = $this->vault->store($player->id, 'nin', $nin, self::class);

        KycRecord::create([
            'playerId' => $player->id,
            'idType' => 'nin',
            'verificationRef' => $token,
            'verificationVendor' => config('identityVendor.driver'),
            'verificationMethod' => 'automated',
            'opayNameMatch' => NameMatcher::match($result->name, $player->registeredName),
            'dateOfBirth' => $dob,
            'verifiedAt' => now(),
        ]);

        // REQ-RG-012 — registry matching is by NIN. RegistryCheckService and
        // FundingService both gate on player.ninHash being set; this was the one place
        // that should have set it and didn't, which meant every real (non-test-
        // shortcut) Tier-1 player would fail-closed on the exclusion-registry check
        // forever, having never actually failed it. Same SHA-256 the vault and
        // exclusionRegistry already use — the raw NIN itself never touches this table.
        $player->forceFill(['kycTier' => 1, 'ninHash' => hash('sha256', $nin)])->save();

        // Story 6.10 — second step of the acquisition funnel (REQ-ANL-007).
        $this->analytics->record('nin_verified', $player, 'web');

        return [
            'status' => 'verified_tier_1',
            'monthly_deposit_limit_kobo' => self::TIER_1_MONTHLY_DEPOSIT_LIMIT_KOBO,
        ];
    }
}
