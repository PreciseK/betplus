<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use LogicException;

/**
 * Model 1's per-round lever for the crash engine (Caged-web/BirdEscape) — the
 * highest-value, lowest-risk slice identified in docs/game-engine-economics.md
 * Part 5 #1: bounds a live round's worst-case aggregate liability, not any
 * individual round's actual outcome. A bet-acceptance limit, not an odds change.
 */
final class BalancedHybridCrashStrategy implements EconomicsModelStrategy
{
    public function __construct(
        private readonly FloatService $float,
        private readonly BalancedHybridParams $params,
    ) {
    }

    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        $round = $context->crashRound;
        if ($round === null) {
            throw new LogicException('BalancedHybridCrashStrategy requires a crash-round context.');
        }

        $capKobo = intdiv($this->float->currentFloatKobo() * $this->params->kellyFactorBasisPoints, 10_000);
        $projectedExposureKobo = $round->exposureKobo + BirdEscapeEngine::worstCaseLiabilityKobo($stakeKobo);

        if ($projectedExposureKobo > $capKobo) {
            throw new TicketEligibilityException(
                'ROUND_EXPOSURE_CAP',
                "This round's worst-case exposure would exceed the {$capKobo} kobo cap for {$gameCode}.",
            );
        }
    }
}
