<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 4.7 — cached snapshots of OPay's /payout/balance response, polled
        // periodically (FloatService). Not the OPAY_FLOAT ledger account itself (that's
        // ledgerAccount/ledgerEntry, the source of truth for money movement) — this is
        // the comparison side for daily reconciliation and the dashboard headline figure
        // (REQ-FLOAT-001, REQ-FLOAT-008).
        Schema::create('floatSnapshot', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opayBalanceKobo');
            $table->string('alertState', 10)->default('ok')->comment('ok|warning|critical|halt (REQ-FLOAT-003)');
            $table->dateTime('polledAt')->useCurrent();

            $table->index('polledAt', 'idx_floatSnapshot_polledAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floatSnapshot');
    }
};
