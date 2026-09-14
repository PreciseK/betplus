<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;

/** Model 1's per-ticket lever for BlackRed, Heritage and Caged-USSD: maxStake = k x currentFloat. */
final class BalancedHybridTicketStrategy implements EconomicsModelStrategy
{
    public function __construct(
        private readonly FloatService $float,
        private readonly BalancedHybridParams $params,
    ) {
    }

    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        $capKobo = intdiv($this->float->currentFloatKobo() * $this->params->kellyFactorBasisPoints, 10_000);

        if ($stakeKobo > $capKobo) {
            throw new TicketEligibilityException(
                'KELLY_STAKE_CAP',
                "Stake exceeds the current Kelly-style cap of {$capKobo} kobo for {$gameCode}.",
            );
        }
    }
}
