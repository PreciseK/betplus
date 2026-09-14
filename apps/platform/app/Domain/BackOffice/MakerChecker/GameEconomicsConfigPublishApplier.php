<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Models\GameEconomicsConfig;
use App\Models\ReviewableChange;
use RuntimeException;

/**
 * Payload: {game_economics_config_id: int}. Re-runs the gate at APPROVAL time, not
 * just proposal time — mirrors CrashConfigPublishApplier/PrizeTablePublishApplier
 * exactly.
 */
final class GameEconomicsConfigPublishApplier implements ReviewableChangeApplier
{
    public function __construct(private readonly GameEconomicsModelGate $gate)
    {
    }

    public function apply(ReviewableChange $change): void
    {
        $config = GameEconomicsConfig::findOrFail($change->payload['game_economics_config_id']);

        $errors = $this->gate->validate($config);
        if ($errors !== []) {
            throw new RuntimeException('Game economics config failed the publication gate at approval time: ' . implode('; ', $errors));
        }

        $config->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
