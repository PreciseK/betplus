<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Ticket\TicketEligibilityException;

/**
 * One implementation per economics model (REQ-BO-015-style registration, same spirit
 * as ReviewableChangeApplier: implement this, then wire it into
 * EconomicsModelStrategyFactory). Resolved fresh at ticket/bet-creation time via
 * EconomicsConfigResolver, injected at the same call site LimitsService's own
 * assertStakeWithinLimits already runs from.
 */
interface EconomicsModelStrategy
{
    /** @throws TicketEligibilityException */
    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void;
}
