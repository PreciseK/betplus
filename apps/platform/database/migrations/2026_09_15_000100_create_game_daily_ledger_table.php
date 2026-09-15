<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (gameCode, day) — updated incrementally in the same transaction
        // each engine already commits settlement in, so Model 3's Daily Loss-Stop
        // strategy has a cheap, always-current running total to check per acceptance
        // instead of re-summing tickets/bets on every request.
        Schema::create('gameDailyLedger', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->date('ledgerDate');
            $table->unsignedBigInteger('grossStakesKobo')->default(0);
            $table->unsignedBigInteger('grossPrizesKobo')->default(0);
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gameCode', 'ledgerDate'], 'uniq_gameDailyLedger_game_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameDailyLedger');
    }
};
