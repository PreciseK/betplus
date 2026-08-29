<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stories 5.2/5.3 — append-only history of cool-offs and self-exclusions
        // (compliance needs to reconstruct "was this player protected at time T", not
        // just the current state — REQ-BO-004 territory). Current status is derived by
        // querying the latest event whose endsAt is in the future, not a mutable status
        // column on player.
        Schema::create('playerProtectionEvent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('type', 20)->comment('cool-off|self-exclusion');
            $table->dateTime('startedAt');
            $table->dateTime('endsAt');
            $table->dateTime('createdAt')->useCurrent();

            $table->index(['playerId', 'endsAt'], 'idx_playerProtectionEvent_player_endsAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playerProtectionEvent');
    }
};
