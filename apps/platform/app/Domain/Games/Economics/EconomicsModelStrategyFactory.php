<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Payout\Float\FloatService;
use App\Models\GameEconomicsConfig;

/**
 * Resolves the strategy for the currently-published config of one game, split by
 * engine shape (ticket vs. the crash engine's shared round) because Model 1's
 * mechanism differs by shape even though the model is the same. A null config
 * (nothing published yet) and every model without a real strategy yet
 * (DAILY_LOSS_STOP, PARI_MUTUEL_POOL — Phase 2/3) fall back to FixedRtpStrategy.
 */
final class EconomicsModelStrategyFactory
{
    public function __construct(private readonly FloatService $float)
    {
    }

    public function forTicketGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridTicketStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }

    public function forCrashGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridCrashStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }
}
