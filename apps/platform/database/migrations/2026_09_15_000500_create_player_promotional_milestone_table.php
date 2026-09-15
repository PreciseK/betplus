<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playerPromotionalMilestone', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('campaignCode', 64);
            $table->unsignedInteger('roundsCompleted')->default(0);
            $table->unsignedInteger('targetRounds')->default(30);
            $table->unsignedBigInteger('bonusAwardedKobo')->default(0);
            $table->boolean('isAwarded')->default(false);
            $table->dateTime('awardedAt')->nullable();
            $table->dateTime('bonusExpiresAt')->nullable();
            $table->dateTime('windowStart');
            $table->dateTime('windowEnd');
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['playerId', 'campaignCode', 'windowStart'], 'uniq_playerMilestone_window');
            $table->index(['isAwarded', 'bonusExpiresAt'], 'idx_playerMilestone_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playerPromotionalMilestone');
    }
};
