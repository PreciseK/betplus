<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Ticket\TicketEligibilityException;

/**
 * Model 3, single strategy for both engine shapes (ticket and crash): the
 * "which game, how much has it lost today" check doesn't differ by shape, unlike
 * BalancedHybrid's per-ticket-cap-vs-per-round-cap split.
 *
 * ponytail: this is the "hard suspend once trigger crossed" version of Daily
 * Loss-Stop, not the spec's original 3-stage progressive throttle (shrink stake ->
 * cap multiplier -> suspend). Upgrade to progressive if a flat cutoff proves too
 * blunt in practice.
 */
final class DailyLossStopStrategy implements EconomicsModelStrategy
{
    public function __construct(
        private readonly GameDailyLedgerService $ledger,
        private readonly DailyLossStopParams $params,
    ) {
    }

    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        $netGgrTodayKobo = $this->ledger->netGgrTodayKobo($gameCode);

        if ($netGgrTodayKobo <= -$this->params->dailyLossCapKobo) {
            throw new TicketEligibilityException(
                'DAILY_LOSS_STOP',
                "{$gameCode} has hit its daily loss cap of {$this->params->dailyLossCapKobo} kobo and is suspended until the next day.",
            );
        }
    }
}
