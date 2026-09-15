<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotionalBoostClaim', function (Blueprint $table) {
            $table->id();
            $table->string('campaignCode', 64);
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->foreignId('ticketId')->nullable()->constrained('ticket')->nullOnDelete();
            $table->string('gameCode', 32);
            $table->unsignedBigInteger('originalPrizeKobo');
            $table->unsignedBigInteger('boostBonusKobo');
            $table->dateTime('claimedAt')->useCurrent();

            $table->unique(['campaignCode', 'playerId'], 'uniq_boostClaim_campaign_player');
            $table->index(['campaignCode', 'claimedAt'], 'idx_boostClaim_campaign_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotionalBoostClaim');
    }
};
