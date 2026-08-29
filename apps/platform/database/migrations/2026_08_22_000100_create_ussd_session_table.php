<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 8.1 / REQ-USSD-003 — live per-turn state lives in apps/ussd's own
        // session store (Redis in the designed architecture; no Redis server exists
        // in this dev sandbox, see apps/ussd/README.md's own ceiling note). This
        // table is the audit mirror the platform owns: apps/ussd itself has no
        // database access (its own composer.json's doc comment), so it POSTs a
        // summary of each turn to /internal/ussd/session, which writes it here.
        Schema::create('ussdSession', function (Blueprint $table) {
            $table->id();
            $table->string('sessionId', 100);
            $table->string('msisdn', 16);
            $table->foreignId('playerId')->nullable()->constrained('player')->nullOnDelete();
            $table->string('screen', 40)->comment('the menu screen this turn rendered');
            $table->string('inputText', 200)->nullable()->comment('the raw turn input, truncated — never a PIN/OTP value');
            $table->string('status', 15)->default('active')->comment('active | ended | expired');
            $table->dateTime('lastTurnAt')->useCurrent();
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['sessionId', 'msisdn'], 'uniq_ussdSession_session_msisdn');
            $table->index('msisdn', 'idx_ussdSession_msisdn');
            $table->index(['status', 'lastTurnAt'], 'idx_ussdSession_cleanup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussdSession');
    }
};
