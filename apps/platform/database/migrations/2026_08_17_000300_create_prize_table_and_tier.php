<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §6.4 Prize tables (REQ-GEC-020..026) — versioned configuration, not code.
        // Maker-checker approval (REQ-BO-003) and the back office publication UI are
        // Epic 6; what's built here is the data shape and the structural publication
        // gate (probabilities sum to 1.0000, RTP <= ceiling) that any future publication
        // flow must pass through. actuarialCertRef and Monte Carlo validation
        // (REQ-GEC-025, REQ-QA-001/002) are compliance artefacts this migration has a
        // column for but does not itself produce — see PrizeTablePublicationGate.
        Schema::create('prizeTable', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->string('stateCode', 10)->nullable()->comment('null = applies to every licensed state (REQ-GEC-026)');
            $table->string('version', 30);
            $table->string('status', 10)->default('draft')->comment('draft | published | retired');
            $table->dateTime('effectiveAt');
            $table->string('actuarialCertRef', 100)->nullable();
            $table->dateTime('publishedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['gameCode', 'stateCode', 'version'], 'uniq_prizeTable_game_state_version');
            $table->index(['gameCode', 'status', 'effectiveAt'], 'idx_prizeTable_lookup');
        });

        Schema::create('prizeTableTier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prizeTableId')->constrained('prizeTable')->cascadeOnDelete();
            $table->unsignedTinyInteger('positions');
            $table->unsignedInteger('multiplierHundredths')->comment('185 = 1.85x');
            $table->unsignedInteger('probabilityNumerator');
            $table->unsignedInteger('probabilityDenominator');

            $table->unique(['prizeTableId', 'positions'], 'uniq_prizeTableTier_table_positions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prizeTableTier');
        Schema::dropIfExists('prizeTable');
    }
};
