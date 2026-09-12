<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One player's stake in one shared round. status transitions PLACED ->
        // CASHED_OUT|LOST exactly once, enforced by BirdEscapeSettlement's
        // conditional `UPDATE ... WHERE status='PLACED'` guard rather than a DB
        // trigger — unlike ticketOutcome/fairnessSeed this table is a mutable status
        // projection, not the fairness ledger of record (that's fairnessSeed's
        // hash chain plus crashRound.commitmentDigest).
        Schema::create('crashBet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roundId')->constrained('crashRound')->restrictOnDelete();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('idempotencyKey', 100)->unique('uniq_crashBet_idempotencyKey');
            $table->string('stateCode', 10);

            $table->unsignedBigInteger('stakeKobo');
            $table->unsignedInteger('autoCashoutMultiplierHundredths')->nullable();

            $table->string('status', 12)->default('PLACED')->comment('PLACED | CASHED_OUT | LOST');
            $table->unsignedInteger('cashedOutAtMultiplierHundredths')->nullable();
            $table->boolean('autoCashedOut')->default(false)->comment('true if the round loop settled it, not a player-initiated request');

            $table->unsignedBigInteger('grossPrizeKobo')->nullable();
            $table->unsignedBigInteger('taxWithheldKobo')->nullable();
            $table->unsignedBigInteger('netCreditKobo')->nullable();
            $table->dateTime('settledAt')->nullable();

            $table->dateTime('createdAt')->useCurrent();

            $table->index(['roundId', 'status'], 'idx_crashBet_round_status');
            $table->index('playerId', 'idx_crashBet_player');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crashBet');
    }
};
