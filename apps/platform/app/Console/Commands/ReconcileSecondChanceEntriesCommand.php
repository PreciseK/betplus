<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Draw\DrawPartnerAdapter;
use App\Models\AuditLog;
use App\Models\HeritageSecondChanceEntry;
use Illuminate\Console\Command;

/**
 * Story 7.8 (REQ-HG-039) — "daily reconciliation between submitted entries and
 * partner-confirmed entries. Any record without a counterpart is a P1 exception."
 * Scope: this run only ever reconciles entries this platform believes are confirmed
 * (partnerReference present) — an entry never even submitted is already visible as
 * SECOND_CHANCE_PENDING/rolled in the operational queue and doesn't need this pass to
 * surface it.
 */
class ReconcileSecondChanceEntriesCommand extends Command
{
    protected $signature = 'heritage:reconcile-second-chance';
    protected $description = 'Story 7.8 — reconcile confirmed second-chance entries against the draw partner (REQ-HG-039)';

    public function handle(DrawPartnerAdapter $partner): int
    {
        $confirmed = HeritageSecondChanceEntry::where('status', 'confirmed')
            ->whereNotNull('partnerReference')
            ->get();

        if ($confirmed->isEmpty()) {
            $this->info('No confirmed second-chance entries to reconcile.');

            return self::SUCCESS;
        }

        $references = $confirmed->pluck('partnerReference')->all();
        $partnerConfirmed = $partner->reconcile($references);

        $exceptions = 0;
        foreach ($confirmed as $entry) {
            if (in_array($entry->partnerReference, $partnerConfirmed, true)) {
                continue;
            }

            $exceptions++;
            AuditLog::create([
                'actorType' => 'system',
                'action' => 'heritage.second_chance.reconciliation_exception',
                'targetTable' => 'heritageSecondChanceEntry',
                'targetId' => $entry->id,
                'reason' => "Betplus holds partnerReference {$entry->partnerReference} as confirmed; the draw partner does not (REQ-HG-039, P1).",
            ]);
        }

        $this->info("Checked {$confirmed->count()} entries, found {$exceptions} without a partner counterpart.");

        // Non-zero exit on any exception so a cron wrapper alerts directly, mirroring
        // ReconcileLedgerCommand's own convention.
        return $exceptions === 0 ? self::SUCCESS : self::FAILURE;
    }
}
