<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // REQ-SEC-034 — tamper-evident. auditLog existed since Story 1.3 without a DB-
        // level immutability guarantee; this closes that gap the same way ledgerEntry/
        // fairnessSeed/ticketOutcome already do. "Shipped off-server" (the other half
        // of REQ-SEC-034) is infra work — a log-forwarding pipeline to external storage
        // — deferred alongside Story 1.4; there's no such pipeline in this repo.
        $this->createImmutabilityTriggers(DB::connection()->getDriverName());
    }

    private function createImmutabilityTriggers(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared('
                CREATE TRIGGER trg_auditLog_no_update
                BEFORE UPDATE ON auditLog
                BEGIN
                    SELECT RAISE(ABORT, \'auditLog is immutable\');
                END;
            ');
            DB::unprepared('
                CREATE TRIGGER trg_auditLog_no_delete
                BEFORE DELETE ON auditLog
                BEGIN
                    SELECT RAISE(ABORT, \'auditLog is immutable\');
                END;
            ');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('
                CREATE TRIGGER trg_auditLog_no_update
                BEFORE UPDATE ON auditLog
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'auditLog is immutable\';
            ');
            DB::unprepared('
                CREATE TRIGGER trg_auditLog_no_delete
                BEFORE DELETE ON auditLog
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'auditLog is immutable\';
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditLog_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditLog_no_delete');
            return;
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditLog_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditLog_no_delete');
        }
    }
};
