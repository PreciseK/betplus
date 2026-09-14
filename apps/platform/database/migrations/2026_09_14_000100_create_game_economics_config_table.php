<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same lifecycle shape as crashConfig/prizeTable (draft -> published via
        // maker-checker), but this table doesn't carry odds/prizes itself — it
        // records which economics model + params governs *acceptance-time*
        // enforcement for a game, resolved fresh at ticket/bet-creation time by
        // EconomicsConfigResolver, exactly like CrashConfigResolver/PrizeTableResolver.
        Schema::create('gameEconomicsConfig', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->string('version', 30);
            $table->string('status', 10)->default('draft')->comment('draft | published | retired');
            $table->string('activeModel', 30)->comment('FIXED_RTP | BALANCED_HYBRID | DAILY_LOSS_STOP | PARI_MUTUEL_POOL');
            $table->json('paramsJson')->comment('model-specific param bag, shape depends on activeModel');
            $table->dateTime('effectiveAt');
            $table->dateTime('publishedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gameCode', 'version'], 'uniq_gameEconomicsConfig_game_version');
            $table->index(['gameCode', 'status', 'effectiveAt'], 'idx_gameEconomicsConfig_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameEconomicsConfig');
    }
};
