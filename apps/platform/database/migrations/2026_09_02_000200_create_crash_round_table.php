<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One shared round, many players' bets against it — structurally different
        // from ticket (one row per isolated play). The crash point is resolved and
        // committed at round creation (same "outcome decided at creation, reveal is
        // presentation" principle as REQ-TKT-004) but crashMultiplierHundredths and
        // the underlying seed are never serialized to players before status=CRASHED
        // — enforced in the controller, not by a DB-level access restriction, exactly
        // like ticketOutcome's fields are readable in this table but only disclosed
        // by RevealTicket's own logic for BlackRed.
        //
        // houseEdgeBasisPoints/bettingWindowSeconds/postCrashIntervalSeconds/
        // growthRateConstant are snapshotted from crashConfig at round start so a
        // later config edit never retroactively changes an in-flight round — the
        // same reason ticket.prizeTableVersion exists instead of a live FK lookup.
        Schema::create('crashRound', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->unsignedBigInteger('roundNumber');
            $table->string('status', 10)->default('BETTING')->comment('BETTING | FLYING | CRASHED');

            $table->foreignId('fairnessSeedId')->constrained('fairnessSeed')->restrictOnDelete();
            $table->string('rngAlgorithm', 40);
            $table->foreignId('crashConfigId')->constrained('crashConfig')->restrictOnDelete();
            $table->unsignedInteger('houseEdgeBasisPoints');
            $table->unsignedInteger('bettingWindowSeconds');
            $table->unsignedInteger('postCrashIntervalSeconds');
            $table->unsignedBigInteger('growthRateConstant');

            $table->unsignedInteger('crashMultiplierHundredths')->comment('342 = 3.42x; hidden from players until status=CRASHED');
            $table->string('commitmentDigest', 64)->comment('sha256(seedHex|roundNumber|crashMultiplierHundredths), published at round start');

            $table->dateTime('bettingStartedAt');
            $table->dateTime('flightStartedAt')->nullable();
            $table->dateTime('crashedAt')->nullable();
            $table->string('engineVersion', 20);
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['gameCode', 'roundNumber'], 'uniq_crashRound_game_roundNumber');
            $table->index(['gameCode', 'status'], 'idx_crashRound_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crashRound');
    }
};
