<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REQ-HG-050 — "stored [on the profile] and snapshotted onto each ticket."
        // The snapshot half lives in ticketOutcome.resultJson (already the generic
        // per-game outcome payload — see CreateHeritageTicket); this is the profile
        // half. Both nullable: a player who has never played Heritage has no
        // tradition/leader preference yet, and BlackRed-only players never see these.
        Schema::table('player', function (Blueprint $table) {
            $table->string('heritageTraditionCode', 30)->nullable()->after('residencyStatus');
            $table->string('heritageLeaderType', 10)->nullable()->after('heritageTraditionCode')->comment('king | queen');
        });
    }

    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropColumn(['heritageTraditionCode', 'heritageLeaderType']);
        });
    }
};
