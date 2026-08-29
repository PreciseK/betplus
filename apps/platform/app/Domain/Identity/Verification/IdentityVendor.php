<?php

declare(strict_types=1);

namespace App\Domain\Identity\Verification;

/**
 * REQ-ID-022 — NIN/BVN verification is performed by a contracted identity vendor.
 * No vendor is contracted yet (PRD C-04); StubIdentityVendor is the only implementation
 * until one is. Swap it in AppServiceProvider once a real vendor's SDK/API exists.
 */
interface IdentityVendor
{
    public function verifyNin(string $nin): IdentityVendorResult;

    public function verifyBvn(string $bvn): IdentityVendorResult;
}
