<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 7.7/7.8 (REQ-HG-030..040) — one row per TIER_SECOND_CHANCE
        // settlement. Lifecycle: SECOND_CHANCE_PENDING (queued, REQ-HG-034) ->
        // submitted -> confirmed (partner reference present, REQ-HG-035) | failed
        // (rolled to next draw, REQ-HG-037) | credited (3-draw failure compensation).
        Schema::create('heritageSecondChanceEntry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticketId')->unique('uniq_heritageSecondChanceEntry_ticket')->constrained('ticket')->restrictOnDelete();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->json('selectedNumbers')->comment('the 5 numbers entered — the player\'s Heritage pick, REQ-HG-030');
            $table->unsignedBigInteger('entryStakeKobo');
            $table->foreignId('drawCalendarEntryId')->nullable()->constrained('heritageDrawCalendarEntry')->nullOnDelete();
            $table->string('status', 25)->default('SECOND_CHANCE_PENDING')
                ->comment('SECOND_CHANCE_PENDING | submitted | confirmed | rolled | failed | credited');
            $table->unsignedTinyInteger('rollCount')->default(0)->comment('REQ-HG-037 — credited after 3 failed roll attempts');
            $table->string('partnerCode', 30)->nullable();
            $table->string('partnerReference', 100)->nullable()->comment('REQ-HG-035 — never presented as lodged without this');
            $table->dateTime('submittedAt')->nullable();
            $table->dateTime('confirmedAt')->nullable();
            $table->dateTime('resultNotifiedAt')->nullable();
            $table->json('resultJson')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'idx_heritageSecondChanceEntry_status');
            $table->index('playerId', 'idx_heritageSecondChanceEntry_player');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heritageSecondChanceEntry');
    }
};
