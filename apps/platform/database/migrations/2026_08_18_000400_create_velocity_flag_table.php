<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 5.7 (REQ-RG-020/021) — surfaces behaviour to a human review queue; it
        // does not itself block play. The review queue UI is Epic 6 back office and
        // doesn't exist yet — this is the table it would read from.
        Schema::create('velocityFlag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('gameCode', 20);
            $table->string('flagType', 30)->comment('rapid_stake_escalation|late_night_velocity|sustained_low_variance');
            $table->text('detail');
            $table->dateTime('createdAt')->useCurrent();

            $table->index('playerId', 'idx_velocityFlag_player');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('velocityFlag');
    }
};
