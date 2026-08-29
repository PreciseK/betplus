<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §6.3 Game Registry (REQ-GEC-010..012). Minimal fields needed for Story 3.6's
        // eligibility gate — the back office console to edit this (REQ-BO-... / Epic 6)
        // is not built; this table is seeded directly for now.
        Schema::create('gameRegistry', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20)->unique('uniq_gameRegistry_gameCode');
            $table->string('engineVersion', 20);
            $table->string('status', 10)->default('SUSPENDED')->comment('ACTIVE | SUSPENDED | RETIRED');
            $table->unsignedBigInteger('minStakeKobo');
            $table->unsignedBigInteger('maxStakeKobo');
            $table->json('enabledChannels')->comment('e.g. ["web","app","ussd"]');
            $table->json('enabledStates')->comment('state codes; the licence footprint for this game');
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameRegistry');
    }
};
