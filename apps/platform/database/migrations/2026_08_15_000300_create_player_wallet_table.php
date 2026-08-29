<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached balances (REQ-WAL-002) — the ledger (sum of ledgerEntry) is the source
        // of truth; this is a read-optimised, transactionally-updated mirror of it.
        // `unsignedBigInteger` gives negative-balance-impossible at the DB level for
        // free (REQ-WAL-041) — MySQL rejects it natively, Laravel emits a CHECK
        // constraint for sqlite.
        Schema::create('playerWallet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->unique('uniq_playerWallet_player')->constrained('player')->restrictOnDelete();
            $table->unsignedBigInteger('playBalanceKobo')->default(0);
            $table->unsignedBigInteger('winningsBalanceKobo')->default(0);
            // Optimistic concurrency: every update is WHERE version = X, and increments
            // it; a concurrent writer's stale WHERE matches zero rows and must retry.
            $table->unsignedInteger('version')->default(0);
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('createdAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playerWallet');
    }
};
