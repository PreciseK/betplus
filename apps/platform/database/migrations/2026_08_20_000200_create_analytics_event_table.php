<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 6.10 (REQ-ANL-001..008). Betplus-owned, append-only (REQ-ANL-005) — no
        // third-party analytics SDK anywhere in this codebase. playerIdHash is a
        // one-way SHA-256, never the raw player id joined back without cause
        // (REQ-ANL-003 — no raw MSISDN/NIN/BVN/DOB/fine coordinates in the payload;
        // properties is validated against a denylist at write time, see
        // AnalyticsEventRecorder). Money/outcome events are emitted server-side only
        // (REQ-ANL-002) — every call site is in Domain/, never in apps/web.
        Schema::create('analyticsEvent', function (Blueprint $table) {
            $table->id();
            $table->string('eventName', 60);
            $table->dateTime('occurredAt')->useCurrent();
            $table->string('playerIdHash', 64)->nullable();
            $table->string('sessionId', 60)->nullable();
            $table->string('channel', 10)->comment('web|app|ussd');
            $table->string('appVersion', 20)->nullable();
            $table->string('gameCode', 20)->nullable();
            $table->string('stateCode', 10)->nullable();
            $table->json('properties')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index('eventName', 'idx_analyticsEvent_eventName');
            $table->index('occurredAt', 'idx_analyticsEvent_occurredAt');
            $table->index(['gameCode', 'stateCode'], 'idx_analyticsEvent_game_state');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            // REQ-ANL-006 — raw events retained 25 months, partitioned monthly so
            // dashboards never scan the whole table (they read rollups anyway; this is
            // for the retention-window sweep).
            DB::statement(<<<'SQL'
                ALTER TABLE analyticsEvent
                PARTITION BY RANGE COLUMNS (occurredAt) (
                    PARTITION p_before_2026_08 VALUES LESS THAN ('2026-08-01'),
                    PARTITION p_2026_08 VALUES LESS THAN ('2026-09-01'),
                    PARTITION p_2026_09 VALUES LESS THAN ('2026-10-01'),
                    PARTITION p_future VALUES LESS THAN (MAXVALUE)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analyticsEvent');
    }
};
