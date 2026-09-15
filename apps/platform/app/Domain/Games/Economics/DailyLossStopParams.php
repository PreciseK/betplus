<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/** Model 3's lever: a hard daily loss cap. Once today's net GGR for the game drops past -dailyLossCapKobo, new stakes are rejected until the ledger date rolls over. */
final class DailyLossStopParams
{
    private function __construct(public readonly int $dailyLossCapKobo)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['daily_loss_cap_kobo'] ?? 0));
    }
}
