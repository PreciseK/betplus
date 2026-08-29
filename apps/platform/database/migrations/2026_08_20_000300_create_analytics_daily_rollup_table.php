<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REQ-ANL-006 — "dashboards query pre-aggregated rollups rather than raw
        // events, so no reporting query can degrade the play path." One row per
        // (day, eventName, channel, gameCode, stateCode) combination.
        Schema::create('analyticsDailyRollup', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('eventName', 60);
            $table->string('channel', 10);
            $table->string('gameCode', 20)->nullable();
            $table->string('stateCode', 10)->nullable();
            $table->unsignedInteger('eventCount');
            $table->unsignedInteger('distinctPlayerCount');
            $table->dateTime('computedAt')->useCurrent();

            $table->unique(['day', 'eventName', 'channel', 'gameCode', 'stateCode'], 'uniq_analyticsDailyRollup_dims');
            $table->index('day', 'idx_analyticsDailyRollup_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyticsDailyRollup');
    }
};
