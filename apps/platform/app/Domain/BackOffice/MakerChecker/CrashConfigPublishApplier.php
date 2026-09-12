<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Games\BirdEscape\CrashConfigPublicationGate;
use App\Models\CrashConfig;
use App\Models\ReviewableChange;
use RuntimeException;

/**
 * Payload: {crash_config_id: int}. Re-runs the same structural gate at APPROVAL time,
 * not just proposal time, so a config can't be edited between proposal and approval
 * and slip through unvalidated — mirrors PrizeTablePublishApplier exactly.
 */
final class CrashConfigPublishApplier implements ReviewableChangeApplier
{
    public function __construct(private readonly CrashConfigPublicationGate $gate)
    {
    }

    public function apply(ReviewableChange $change): void
    {
        $config = CrashConfig::findOrFail($change->payload['crash_config_id']);

        $errors = $this->gate->validate($config);
        if ($errors !== []) {
            throw new RuntimeException('Crash config failed the publication gate at approval time: ' . implode('; ', $errors));
        }

        $config->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
