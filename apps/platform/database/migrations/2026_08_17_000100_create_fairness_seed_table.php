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
        // Story 3.2 — one CSPRNG seed per ticket, issued BEFORE the ticket exists (D-08:
        // the engine resolves outside the commitment transaction), so this table has no
        // ticketId at insert time. The ticket later stores this row's id as rngSeedRef
        // (REQ-RNG-003). Hash-chained and append-only (REQ-RNG-004) — each row's hash
        // covers its own payload plus the previous row's hash, so any historical edit or
        // reordering breaks the chain from that point forward.
        Schema::create('fairnessSeed', function (Blueprint $table) {
            $table->id();
            $table->string('seedHex', 64)->comment('32 bytes from random_bytes(), hex-encoded');
            $table->string('algorithm', 40)->default('CTR_DRBG-random_bytes');
            $table->string('previousHash', 64)->nullable()->comment('null only for the first row ever written');
            $table->string('hash', 64)->comment('sha256(previousHash . seedHex . issuedAt . id-1)');
            $table->dateTime('issuedAt')->useCurrent();

            $table->index('issuedAt', 'idx_fairnessSeed_issuedAt');
        });

        $this->createImmutabilityTriggers(DB::connection()->getDriverName());
    }

    private function createImmutabilityTriggers(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared('
                CREATE TRIGGER trg_fairnessSeed_no_update
                BEFORE UPDATE ON fairnessSeed
                BEGIN
                    SELECT RAISE(ABORT, \'fairnessSeed is append-only\');
                END;
            ');
            DB::unprepared('
                CREATE TRIGGER trg_fairnessSeed_no_delete
                BEFORE DELETE ON fairnessSeed
                BEGIN
                    SELECT RAISE(ABORT, \'fairnessSeed is append-only\');
                END;
            ');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::unprepared('
                CREATE TRIGGER trg_fairnessSeed_no_update
                BEFORE UPDATE ON fairnessSeed
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'fairnessSeed is append-only\';
            ');
            DB::unprepared('
                CREATE TRIGGER trg_fairnessSeed_no_delete
                BEFORE DELETE ON fairnessSeed
                FOR EACH ROW
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'fairnessSeed is append-only\';
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_fairnessSeed_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_fairnessSeed_no_delete');
        }
        Schema::dropIfExists('fairnessSeed');
    }
};
