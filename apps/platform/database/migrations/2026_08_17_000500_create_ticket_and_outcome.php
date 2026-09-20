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
        // §7.11 Ticket Lifecycle. This build settles synchronously inside ticket creation
        // (see CreateTicket) rather than deferring settlement to a later "reveal" call —
        // the PRD's data-flow diagram shows settlement immediately after COMMIT, and
        // REQ-TKT-004 ("reveal is presentation") supports treating reveal as a pure
        // disclosure read with no business logic of its own. So `ticket` reaches a
        // terminal status (SETTLED) within the creation request; revealedAt is the one
        // legitimate later mutation (set by GET .../reveal), not a general-purpose
        // status machine — see the note on why no immutability trigger sits on this
        // table the way one does on ticketOutcome below.
        Schema::create('ticket', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique('uniq_ticket_reference');
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('gameCode', 20);
            $table->string('idempotencyKey', 100)->unique('uniq_ticket_idempotencyKey');

            // Jurisdiction (REQ-GEO-001, folded into this table rather than a separate
            // ticketJurisdiction table — no second consumer justifies the extra join yet).
            $table->string('stateCode', 10);
            $table->decimal('attributionConfidence', 4, 3);
            $table->string('jurisdictionRulesetVersion', 30);

            $table->unsignedBigInteger('stakeKobo');
            $table->json('predictionJson');
            $table->unsignedTinyInteger('positions');

            $table->string('prizeTableVersion', 30);
            $table->foreignId('rngSeedRef')->constrained('fairnessSeed')->restrictOnDelete();
            $table->string('rngAlgorithm', 40);
            $table->string('engineVersion', 20);

            $table->string('status', 20)->default('CREATED')
                ->comment('CREATED|FUNDED|IN_PLAY|REVEALED|SETTLED|PAID|CLOSED|VOIDED|REFUNDED');
            $table->dateTime('revealedAt')->nullable();

            $table->dateTime('createdAt')->useCurrent();

            $table->index('playerId', 'idx_ticket_player');
            $table->index('stateCode', 'idx_ticket_stateCode');
            $table->index('createdAt', 'idx_ticket_createdAt');
        });

        Schema::create('ticketOutcome', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticketId')->unique('uniq_ticketOutcome_ticket')->constrained('ticket')->restrictOnDelete();
            $table->json('resultJson');
            $table->boolean('won');
            $table->unsignedBigInteger('grossPrizeKobo');
            $table->unsignedBigInteger('taxWithheldKobo');
            $table->unsignedBigInteger('netCreditKobo');
            $table->unsignedInteger('taxRateBasisPoints');
            $table->string('taxBasisLabel', 40);
            $table->string('taxRulesetVersion', 30);
            $table->string('digest', 64)->comment('sha256 of the canonical outcome, from the engine');
            $table->dateTime('createdAt')->useCurrent();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                // REQ-NFR-032 — monthly range partitions, declared at creation.
                DB::statement(<<<'SQL'
                    ALTER TABLE ticket
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
        // ticketOutcome is genuinely write-once (REQ-TKT-008): the engine resolves once,
        // before the ticket even exists, and this row is inserted alongside the ticket
        // in the same commit. Unlike ticket.revealedAt, nothing legitimately updates it.
        if ($driver === 'sqlite') {
            DB::unprepared('
                CREATE TRIGGER trg_ticketOutcome_no_update
                BEFORE UPDATE ON ticketOutcome
                BEGIN
                    SELECT RAISE(ABORT, \'ticketOutcome is immutable\');
                END;
            ');
            DB::unprepared('
                CREATE TRIGGER trg_ticketOutcome_no_delete
                BEFORE DELETE ON ticketOutcome
                BEGIN
                    SELECT RAISE(ABORT, \'ticketOutcome is immutable\');
                END;
            ');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('
                CREATE TRIGGER trg_ticketOutcome_no_update
                BEFORE UPDATE ON ticketOutcome
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'ticketOutcome is immutable\';
            ');
            DB::unprepared('
                CREATE TRIGGER trg_ticketOutcome_no_delete
                BEFORE DELETE ON ticketOutcome
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'ticketOutcome is immutable\';
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ticketOutcome_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ticketOutcome_no_delete');
        }
        Schema::dropIfExists('ticketOutcome');
        Schema::dropIfExists('ticket');
    }
};
