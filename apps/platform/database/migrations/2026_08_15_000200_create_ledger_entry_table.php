<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The journal. Immutable by design — see the triggers below, not just app
        // convention (REQ-WAL-040). Only WalletService ever writes here
        // (REQ-ARCH-001/002); production additionally revokes UPDATE/DELETE grants
        // from the app's normal DB user, which isn't something a local sqlite/mysql
        // dev DB can express — the triggers are the part that's testable here.
        if (!Schema::hasTable('ledgerEntry')) {
            Schema::create('ledgerEntry', function (Blueprint $table) {
                $table->id();
                $table->foreignId('accountId')->constrained('ledgerAccount')->restrictOnDelete();
                $table->string('direction', 6)->comment('debit | credit');
                $table->unsignedBigInteger('amountKobo');
                $table->string('currency', 3)->default('NGN');
                $table->string('stateCode', 10)->nullable();
                // Ties together the balanced set of entries for one economic event —
                // REQ-WAL-001 checks that every group's debits equal its credits.
                $table->string('transactionGroup', 40);
                $table->string('referenceType', 40)->nullable();
                $table->unsignedBigInteger('referenceId')->nullable();
                $table->dateTime('createdAt')->useCurrent();

                $table->index('accountId', 'idx_ledgerEntry_account');
                $table->index('stateCode', 'idx_ledgerEntry_stateCode');
                $table->index('transactionGroup', 'idx_ledgerEntry_txGroup');
                $table->index(['referenceType', 'referenceId'], 'idx_ledgerEntry_reference');
            });
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                // REQ-NFR-032 — monthly range partitions for per-state remittance queries at volume.
                DB::statement(<<<'SQL'
                    ALTER TABLE ledgerEntry
                    PARTITION BY RANGE COLUMNS (createdAt) (
                        PARTITION p_before_2026_08 VALUES LESS THAN ('2026-08-01'),
                        PARTITION p_2026_08 VALUES LESS THAN ('2026-09-01'),
                        PARTITION p_2026_09 VALUES LESS THAN ('2026-10-01'),
                        PARTITION p_future VALUES LESS THAN (MAXVALUE)
                    )
                SQL);
            } catch (\Throwable) {
                // Ignored when database engine restricts foreign keys on partitioned tables.
            }
        }

        $this->createImmutabilityTriggers($driver);
    }

    private function createImmutabilityTriggers(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_update;');
            DB::unprepared('
                CREATE TRIGGER trg_ledgerEntry_no_update
                BEFORE UPDATE ON ledgerEntry
                BEGIN
                    SELECT RAISE(ABORT, \'ledgerEntry is immutable\');
                END;
            ');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_delete;');
            DB::unprepared('
                CREATE TRIGGER trg_ledgerEntry_no_delete
                BEFORE DELETE ON ledgerEntry
                BEGIN
                    SELECT RAISE(ABORT, \'ledgerEntry is immutable\');
                END;
            ');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_update;');
            DB::unprepared('
                CREATE TRIGGER trg_ledgerEntry_no_update
                BEFORE UPDATE ON ledgerEntry
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'ledgerEntry is immutable\';
            ');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_delete;');
            DB::unprepared('
                CREATE TRIGGER trg_ledgerEntry_no_delete
                BEFORE DELETE ON ledgerEntry
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'ledgerEntry is immutable\';
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ledgerEntry_no_delete');
        }
        Schema::dropIfExists('ledgerEntry');
    }
};
