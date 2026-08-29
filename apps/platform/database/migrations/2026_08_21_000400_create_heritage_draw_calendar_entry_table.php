<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 7.7 (REQ-HG-032/033) — configuration, never hardcoded draw names or
        // times; back office (not built this pass) or a partner-calendar sync job
        // would maintain this. Handles multiple draws per day and same-day schedule
        // shifts because it's just rows, not a fixed cron expression.
        Schema::create('heritageDrawCalendarEntry', function (Blueprint $table) {
            $table->id();
            $table->string('partnerCode', 30);
            $table->string('drawName', 60);
            $table->dateTime('scheduledAt');
            $table->dateTime('cutoffAt')->comment('REQ-HG-032 — 20 minutes before scheduledAt by convention, but stored explicitly, not derived, so a partner-specific cutoff never needs a code change');
            $table->string('status', 15)->default('open')->comment('open | closed | resulted | cancelled');
            $table->dateTime('createdAt')->useCurrent();

            $table->index(['partnerCode', 'status', 'cutoffAt'], 'idx_heritageDrawCalendar_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heritageDrawCalendarEntry');
    }
};
