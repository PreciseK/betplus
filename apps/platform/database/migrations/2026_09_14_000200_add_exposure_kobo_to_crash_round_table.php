<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running worst-case liability of every PLACED bet this round (stake x the
        // engine's absolute max multiplier, not the eventual — still secret — crash
        // point). BalancedHybridCrashStrategy compares this against a Kelly-style cap
        // before accepting each new bet; PlaceCrashBet increments it in the same
        // transaction it already commits a bet in.
        Schema::table('crashRound', function (Blueprint $table) {
            $table->unsignedBigInteger('exposureKobo')->default(0)->after('crashMultiplierHundredths');
        });
    }

    public function down(): void
    {
        Schema::table('crashRound', function (Blueprint $table) {
            $table->dropColumn('exposureKobo');
        });
    }
};
