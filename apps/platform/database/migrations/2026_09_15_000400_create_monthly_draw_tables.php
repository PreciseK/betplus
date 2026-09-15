<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthlyDrawPool', function (Blueprint $table) {
            $table->id();
            $table->string('monthPeriod', 7)->unique('uniq_monthlyDrawPool_period'); // e.g. '2026-09'
            $table->string('status', 20)->default('OPEN')->comment('OPEN | SNAPSHOTTED | DRAWN | DISBURSED');
            $table->unsignedBigInteger('totalTurnoverKobo')->default(0);
            $table->unsignedBigInteger('allocatedPrizePoolKobo')->default(0);
            $table->unsignedInteger('totalTicketsIssued')->default(0);
            $table->string('drawSeedRef')->nullable();
            $table->json('winnersJson')->nullable();
            $table->dateTime('drawnAt')->nullable();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('createdAt')->useCurrent();
        });

        Schema::create('monthlyDrawEntry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poolId')->constrained('monthlyDrawPool')->cascadeOnDelete();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->unsignedBigInteger('turnoverKobo');
            $table->unsignedInteger('ticketCount');
            $table->unsignedBigInteger('ticketRangeStart');
            $table->unsignedBigInteger('ticketRangeEnd');
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['poolId', 'playerId'], 'uniq_monthlyDrawEntry_pool_player');
            $table->index(['poolId', 'ticketRangeStart', 'ticketRangeEnd'], 'idx_monthlyDrawEntry_ranges');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthlyDrawEntry');
        Schema::dropIfExists('monthlyDrawPool');
    }
};
