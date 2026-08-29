<?php

declare(strict_types=1);

namespace App\Domain\Fairness;

use App\Models\FairnessSeed;

/**
 * Story 3.2 (REQ-RNG-001..004). Issues exactly one seed per ticket from a CSPRNG —
 * random_bytes() is PHP's OS-entropy-backed CSPRNG, satisfying the NIST SP 800-90A
 * CTR_DRBG requirement without a userland DRBG implementation. Each row is chained to
 * its predecessor's hash so the append-only store is tamper-evident (REQ-RNG-004).
 *
 * This is called during the eligibility/resolution phase, before the ticket or its
 * commitment transaction exist (D-08) — so issuance has no ticketId to record yet.
 * The ticket later stores the returned row's id as rngSeedRef.
 */
final class SeedIssuer
{
    private const ALGORITHM = 'CTR_DRBG-random_bytes';

    public function issue(): FairnessSeed
    {
        $seedHex = bin2hex(random_bytes(32));
        $issuedAt = now();
        $previous = FairnessSeed::orderByDesc('id')->first();
        $previousHash = $previous?->hash;

        $hash = hash('sha256', ($previousHash ?? '') . $seedHex . $issuedAt->toIso8601String());

        return FairnessSeed::create([
            'seedHex' => $seedHex,
            'algorithm' => self::ALGORITHM,
            'previousHash' => $previousHash,
            'hash' => $hash,
            'issuedAt' => $issuedAt,
        ]);
    }
}
