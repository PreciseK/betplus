<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poolDraw', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            // BlackRed's pick-length (1-5) — one pool per length, so they never mix
            // stakes/outcomes. Null for Heritage and Caged, which run one pool per
            // window with no sub-key.
            $table->unsignedTinyInteger('poolKey')->nullable();
            $table->string('status', 10)->default('open')->comment('open | closed | settled');
            $table->dateTime('opensAt');
            $table->dateTime('closesAt');
            $table->json('drawnOutcomeJson')->nullable();
            $table->foreignId('fairnessSeedId')->nullable()->constrained('fairnessSeed')->nullOnDelete();
            $table->unsignedBigInteger('grossStakedKobo')->default(0);
            $table->unsignedBigInteger('rakeKobo')->default(0);
            $table->unsignedBigInteger('netPoolKobo')->default(0);
            // BlackRed/Caged (no tiers) use this plain scalar rollover.
            $table->unsignedBigInteger('rolloverInKobo')->default(0);
            // Heritage (tiered) uses this instead: {"5": kobo, "4": kobo, "3": kobo, "2": kobo}.
            $table->json('tierRolloverJson')->nullable();
            $table->dateTime('drawnAt')->nullable();
            $table->dateTime('settledAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gameCode', 'poolKey', 'opensAt'], 'uniq_poolDraw_game_key_window');
            $table->index(['gameCode', 'poolKey', 'status'], 'idx_poolDraw_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poolDraw');
    }
};
