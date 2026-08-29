<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Vault\IdentityVaultService;
use App\Domain\Identity\Verification\IdentityVendor;
use App\Models\KycRecord;
use App\Models\Player;

/**
 * Verify BVN and reach Tier 2 (REQ-ID-020). Not its own epics.md story — it exists
 * because REQ-PAY-004 requires a verified BVN on file before OPay's BankAccount
 * collection method can be used at all, and that's a hard blocker for Story 2.3
 * (funding) that nothing in Epic 1 covers. Deliberately mirrors NinVerificationService.
 */
final class BvnVerificationService
{
    public function __construct(
        private readonly IdentityVendor $vendor,
        private readonly IdentityVaultService $vault,
    ) {
    }

    /** @return array{status: string} */
    public function verify(Player $player, string $bvn): array
    {
        if ($player->kycTier < 1) {
            // BVN verification presumes Tier 1 (NIN, adult, OPay name) already happened.
            return ['status' => 'tier_1_required'];
        }

        $result = $this->vendor->verifyBvn($bvn);

        if (!$result->verified) {
            KycRecord::create([
                'playerId' => $player->id,
                'idType' => 'bvn',
                'verificationMethod' => 'automated',
                'rejectionReason' => $result->rejectionReason ?? 'BVN could not be verified.',
            ]);

            return ['status' => 'bvn_not_verified'];
        }

        $token = $this->vault->store($player->id, 'bvn', $bvn, self::class);

        KycRecord::create([
            'playerId' => $player->id,
            'idType' => 'bvn',
            'verificationRef' => $token,
            'verificationVendor' => config('identityVendor.driver'),
            'verificationMethod' => 'automated',
            'opayNameMatch' => NameMatcher::match($result->name, $player->registeredName),
            'verifiedAt' => now(),
        ]);

        $player->forceFill(['kycTier' => 2])->save();

        return ['status' => 'verified_tier_2'];
    }
}
