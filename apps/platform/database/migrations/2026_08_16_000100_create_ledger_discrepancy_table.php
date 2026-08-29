<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 2.7 — every open row here is an unreconciled record; M11's target is
        // zero of these at T+1. 'checkType' has room for 'clearing_account' and
        // 'tax_payable' once OPay settlement files and remittance tracking exist
        // (REQ-WAL-043) — only 'wallet_balance' is populated today.
        Schema::create('ledgerDiscrepancy', function (Blueprint $table) {
            $table->id();
            $table->string('checkType', 30)->comment('wallet_balance | clearing_account | tax_payable');
            $table->unsignedBigInteger('subjectId')->comment('playerId for wallet_balance');
            $table->bigInteger('expectedKobo')->comment('Summed from the ledger');
            $table->bigInteger('actualKobo')->comment('The cached value that disagreed with it');
            $table->bigInteger('differenceKobo');
            $table->string('severity', 10)->default('p1');
            $table->string('status', 10)->default('open')->comment('open | resolved');
            $table->dateTime('detectedAt')->useCurrent();
            $table->dateTime('resolvedAt')->nullable();

            $table->index('checkType', 'idx_discrepancy_checkType');
            $table->index('status', 'idx_discrepancy_status');
            $table->index('subjectId', 'idx_discrepancy_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgerDiscrepancy');
    }
};
