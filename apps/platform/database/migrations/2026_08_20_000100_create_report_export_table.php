<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 6.9 (REQ-BO-008/023) — a report request runs asynchronously and
        // delivers a signed, expiring download link rather than streaming a wide date
        // range synchronously through a request worker. This row IS the audit record
        // (actor, filter, row count) REQ-BO-023 asks for.
        Schema::create('reportExport', function (Blueprint $table) {
            $table->id();
            $table->string('reportType', 30)->comment('financial');
            $table->foreignId('requestedBy')->constrained('institutionUser')->restrictOnDelete();
            $table->json('filter')->comment('date range, game_code, state_code as submitted');
            $table->string('status', 20)->default('queued')->comment('queued|completed|failed');
            $table->string('filePath', 255)->nullable();
            $table->unsignedInteger('rowCount')->nullable();
            $table->string('failureReason', 500)->nullable();
            $table->dateTime('expiresAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('completedAt')->nullable();

            $table->index('requestedBy', 'idx_reportExport_requestedBy');
            $table->index('status', 'idx_reportExport_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportExport');
    }
};
