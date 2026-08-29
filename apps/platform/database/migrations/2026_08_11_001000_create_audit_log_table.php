<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every operator action, from day one (REQ-BO-002). The back office arrives in Epic 6,
        // but an audit trail that starts late has its hole exactly where it matters most.
        Schema::create('auditLog', function (Blueprint $table) {
            $table->id();
            $table->string('actorType', 20)->comment('institutionUser | system | player');
            $table->unsignedBigInteger('actorId')->nullable();
            $table->string('action', 100);
            $table->string('targetTable', 64)->nullable();
            $table->unsignedBigInteger('targetId')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('ipAddress', 45)->nullable();
            $table->string('userAgent', 500)->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index(['actorType', 'actorId'], 'idx_audit_actor');
            $table->index('action', 'idx_audit_action');
            $table->index(['targetTable', 'targetId'], 'idx_audit_target');
            $table->index('createdAt', 'idx_audit_createdAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditLog');
    }
};
