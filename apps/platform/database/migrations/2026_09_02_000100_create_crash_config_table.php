<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BirdEscape's analogue of prizeTable (2026_08_17_000300): versioned,
        // draft/published/retired, maker-checker-gated configuration, not code
        // (REQ-GEC-020 in spirit). A crash game's margin is one scalar house-edge
        // parameter feeding the engine's crash-point formula, not a per-tier
        // multiplier list, so there is no child "tier" table here.
        Schema::create('crashConfig', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->string('version', 30);
            $table->string('status', 10)->default('draft')->comment('draft | published | retired');
            $table->unsignedInteger('houseEdgeBasisPoints')->comment('500 = 5% house edge, 9500 = 95% RTP ceiling');
            $table->unsignedInteger('bettingWindowSeconds')->default(5);
            $table->unsignedInteger('postCrashIntervalSeconds')->default(5);
            $table->unsignedBigInteger('growthRateConstant')->comment('curve-steepness constant for the public growth formula');
            $table->dateTime('effectiveAt');
            $table->string('actuarialCertRef', 100)->nullable();
            $table->dateTime('publishedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gameCode', 'version'], 'uniq_crashConfig_game_version');
            $table->index(['gameCode', 'status', 'effectiveAt'], 'idx_crashConfig_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crashConfig');
    }
};
