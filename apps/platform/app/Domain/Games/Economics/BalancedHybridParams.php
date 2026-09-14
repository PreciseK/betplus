<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/** Model 1's Phase-1 lever: a Kelly-style stake/exposure cap as a fraction of the current float. */
final class BalancedHybridParams
{
    private function __construct(public readonly int $kellyFactorBasisPoints)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['kelly_factor_basis_points'] ?? 0));
    }
}
