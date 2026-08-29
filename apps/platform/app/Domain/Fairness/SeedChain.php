<?php

declare(strict_types=1);

namespace App\Domain\Fairness;

use App\Models\FairnessSeed;

/**
 * Verifies the hash chain SeedIssuer writes (REQ-RNG-004). Walks the whole table in
 * insertion order and recomputes each hash from its own payload plus the previous
 * row's — any edited or reordered row breaks the chain from that point on.
 */
final class SeedChain
{
    /** @return array{valid: bool, brokenAtId: int|null} */
    public function verify(): array
    {
        $previousHash = null;

        foreach (FairnessSeed::orderBy('id')->cursor() as $seed) {
            if ($seed->previousHash !== $previousHash) {
                return ['valid' => false, 'brokenAtId' => $seed->id];
            }

            $expectedHash = hash('sha256', ($previousHash ?? '') . $seed->seedHex . $seed->issuedAt->toIso8601String());
            if (!hash_equals($expectedHash, $seed->hash)) {
                return ['valid' => false, 'brokenAtId' => $seed->id];
            }

            $previousHash = $seed->hash;
        }

        return ['valid' => true, 'brokenAtId' => null];
    }
}
