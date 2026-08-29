<?php

declare(strict_types=1);

namespace App\Domain\Identity\Verification;

/**
 * No identity vendor is contracted yet (PRD C-04). This only confirms the NIN is
 * well-formed (11 digits) — it cannot verify anything against a real national registry
 * and deliberately never returns a name (so downstream name-matching correctly lands on
 * "not_checked" rather than fabricating a match/mismatch). Replace entirely, don't extend,
 * once a real vendor is contracted.
 */
final class StubIdentityVendor implements IdentityVendor
{
    public function verifyNin(string $nin): IdentityVendorResult
    {
        if (!preg_match('/^\d{11}$/', $nin)) {
            return new IdentityVendorResult(
                verified: false,
                name: null,
                reference: '',
                rejectionReason: 'NIN must be 11 digits.',
            );
        }

        return new IdentityVendorResult(
            verified: true,
            name: null,
            reference: 'stub:' . hash('sha256', $nin),
        );
    }

    public function verifyBvn(string $bvn): IdentityVendorResult
    {
        if (!preg_match('/^\d{11}$/', $bvn)) {
            return new IdentityVendorResult(
                verified: false,
                name: null,
                reference: '',
                rejectionReason: 'BVN must be 11 digits.',
            );
        }

        return new IdentityVendorResult(
            verified: true,
            name: null,
            reference: 'stub:' . hash('sha256', $bvn),
        );
    }
}
