<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * Model 1's levers: a Kelly-style stake/exposure cap as a fraction of the current
 * float (Phase 1), plus an optional reserve-fund siphon — a % of each day's net GGR
 * moved into the segregated RESERVE_FUND ledger account (Phase 2). Zero means no
 * siphon; the field is optional so Phase 1 configs without it keep working.
 */
final class BalancedHybridParams
{
    private function __construct(
        public readonly int $kellyFactorBasisPoints,
        public readonly int $reserveSiphonBps,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['kelly_factor_basis_points'] ?? 0),
            (int) ($data['reserve_siphon_bps'] ?? 0),
        );
    }
}
