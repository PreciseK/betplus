<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Games\PrizeTable\PrizeTablePublicationGate;
use App\Models\PrizeTable;
use App\Models\ReviewableChange;
use RuntimeException;

/**
 * Story 6.8 — payload: {prize_table_id: int}. Re-runs the same structural gate
 * BlackRedGameSeeder runs (probabilities, RTP ceiling, actuarial cert reference) at
 * APPROVAL time, not just proposal time, so a table can't be edited between proposal
 * and approval and slip through unvalidated.
 */
final class PrizeTablePublishApplier implements ReviewableChangeApplier
{
    public function __construct(private readonly PrizeTablePublicationGate $gate)
    {
    }

    public function apply(ReviewableChange $change): void
    {
        $table = PrizeTable::with('tiers')->findOrFail($change->payload['prize_table_id']);

        $errors = $this->gate->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));
        if ($errors !== []) {
            throw new RuntimeException('Prize table failed the publication gate at approval time: ' . implode('; ', $errors));
        }

        $table->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
