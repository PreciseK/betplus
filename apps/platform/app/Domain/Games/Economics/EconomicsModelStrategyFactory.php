<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Payout\Float\FloatService;
use App\Models\GameEconomicsConfig;

/**
 * Resolves the strategy for the currently-published config of one game, split by
 * engine shape (ticket vs. the crash engine's shared round) because Model 1's
 * mechanism differs by shape even though the model is the same — Model 3's
 * DailyLossStopStrategy doesn't, so both shapes share one instance. A null config
 * (nothing published yet) and every model without a real strategy yet
 * (PARI_MUTUEL_POOL — Phase 3) fall back to FixedRtpStrategy.
 */
final class EconomicsModelStrategyFactory
{
    public function __construct(
        private readonly FloatService $float,
        private readonly GameDailyLedgerService $ledger,
    ) {
    }

    public function forTicketGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridTicketStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            'DAILY_LOSS_STOP' => new DailyLossStopStrategy($this->ledger, DailyLossStopParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }

    public function forCrashGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridCrashStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            'DAILY_LOSS_STOP' => new DailyLossStopStrategy($this->ledger, DailyLossStopParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }
}
