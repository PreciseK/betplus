<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile';
    protected $description = 'Story 2.7 — compare cached wallet balances against the summed ledger (REQ-WAL-043)';

    public function handle(ReconciliationService $reconciliation): int
    {
        $result = $reconciliation->reconcileWalletBalances();

        $this->info("Checked {$result['checked']} wallets, found {$result['discrepancies']} discrepancies.");

        // Non-zero exit on any discrepancy so a cron wrapper can alert on it directly,
        // independent of whoever eventually reads the ledgerDiscrepancy/auditLog rows.
        return $result['discrepancies'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
