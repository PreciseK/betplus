<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * Model 2 (Fixed RTP) — no acceptance-time throttling beyond the RTP ceiling already
 * enforced when the game's PrizeTable/CrashConfig was published. Also the safe
 * default when no GameEconomicsConfig has been published yet for a game (today's
 * behaviour, unchanged), and the temporary stand-in for DAILY_LOSS_STOP and
 * PARI_MUTUEL_POOL until Phase 2/3 build their real strategies
 * (EconomicsModelStrategyFactory's match default).
 */
final class FixedRtpStrategy implements EconomicsModelStrategy
{
    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        // Deliberately empty.
    }
}
