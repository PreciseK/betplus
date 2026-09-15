<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * Model 4's params. `tierAllocationBps` is Heritage-only (keyed "2".."5", must sum to
 * 10000 when present) — BlackRed/Caged have no tiers and ignore it.
 */
final class PariMutuelPoolParams
{
    private function __construct(
        public readonly int $rakeBps,
        public readonly int $poolWindowMinutes,
        /** @var array<string, int> */
        public readonly array $tierAllocationBps,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int> $tiers */
        $tiers = is_array($data['tier_allocation_bps'] ?? null) ? $data['tier_allocation_bps'] : [];

        return new self(
            (int) ($data['rake_bps'] ?? 0),
            (int) ($data['pool_window_minutes'] ?? 60),
            $tiers,
        );
    }
}
