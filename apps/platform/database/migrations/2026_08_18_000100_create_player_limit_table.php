<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 5.1 — one row per (player, limitKey). REQ-RG-002: limits apply across
        // every game combined, so there is no gameCode column — the whole point.
        // REQ-RG-003: a reduction applies to currentValue immediately; an increase is
        // held in pendingValue/pendingEffectiveAt until the 24h delay elapses.
        Schema::create('playerLimit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('limitKey', 20)->comment('deposit-daily|deposit-weekly|deposit-monthly|stake-daily|stake-weekly|session-time');
            $table->string('unit', 10)->comment('kobo|minutes');
            $table->unsignedBigInteger('currentValue');
            $table->unsignedBigInteger('pendingValue')->nullable();
            $table->dateTime('pendingEffectiveAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['playerId', 'limitKey'], 'uniq_playerLimit_player_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playerLimit');
    }
};
