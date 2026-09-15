<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poolEntry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poolDrawId')->constrained('poolDraw')->cascadeOnDelete();
            $table->foreignId('ticketId')->constrained('ticket')->cascadeOnDelete();
            $table->foreignId('playerId')->constrained('player')->cascadeOnDelete();
            // Duplicated from ticket.predictionJson so settlement never has to join
            // back to ticket for its hot-path comparison against the drawn outcome.
            $table->json('predictionJson');
            $table->unsignedBigInteger('stakeKobo');
            // Heritage's match count (2-5) once settled; null for BlackRed/Caged,
            // which have no tiers, and null for anyone until settlement runs.
            $table->unsignedTinyInteger('matchTier')->nullable();
            $table->boolean('won')->nullable();
            $table->unsignedBigInteger('payoutKobo')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index(['poolDrawId', 'matchTier'], 'idx_poolEntry_settlement_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poolEntry');
    }
};
