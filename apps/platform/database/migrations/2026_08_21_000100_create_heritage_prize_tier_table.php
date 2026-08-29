<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 7.2/7.4 — Heritage's tiers hang off the SAME prizeTable parent row as
        // BlackRed's (gameCode/stateCode/version/status/effectiveAt/actuarialCertRef
        // are already fully generic — REQ-GEC-020/026), but the tier SHAPE is
        // different: BlackRed's prizeTableTier is keyed by `positions` (a fair-coin
        // fraction per pick length); Heritage's four tiers (PRD §9.4) are named,
        // basis-point-weighted, and each declares its own outcome kind (cash prize,
        // draw entry, or nothing). Forcing both into one child-table shape would mean
        // either abusing `positions` for something it doesn't mean, or making that
        // table's columns nullable/overloaded for two unrelated concepts — a second
        // child table is the smaller, honester diff.
        Schema::create('heritagePrizeTier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prizeTableId')->constrained('prizeTable')->cascadeOnDelete();
            $table->string('tierName', 30)->comment('TIER_JACKPOT | TIER_HIGH | TIER_SECOND_CHANCE | TIER_LOSS');
            $table->unsignedInteger('probabilityBasisPoints')->comment('sum across a table\'s 4 tiers must equal 10000');
            $table->unsignedInteger('multiplierHundredths')->comment('2500 = 25x; 0 for draw_entry/none tiers');
            $table->string('outcomeType', 15)->comment('cash | draw_entry | none');

            $table->unique(['prizeTableId', 'tierName'], 'uniq_heritagePrizeTier_table_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heritagePrizeTier');
    }
};
