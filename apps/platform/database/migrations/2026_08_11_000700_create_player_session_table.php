<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web and app sessions. Redis is primary (REQ-ID-026); this table is the audit mirror.
        // USSD session state is a separate concern and arrives with Epic 8.
        Schema::create('playerSession', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->nullable()->constrained('player')->nullOnDelete();
            $table->string('msisdn', 16);
            $table->string('sessionToken', 128)->unique('uniq_session_token');
            $table->string('refreshTokenHash', 128)->nullable()
                ->comment('Rotation with reuse detection (REQ-ID-026)');
            $table->unsignedBigInteger('refreshTokenFamily')->nullable()
                ->comment('Reuse of any token in a family revokes the whole family');
            $table->string('channel', 10)->comment('web | app');
            $table->string('ipAddress', 45)->nullable();
            $table->string('userAgent', 500)->nullable();
            $table->dateTime('expiresAt');
            $table->dateTime('terminatedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index('playerId', 'idx_session_player');
            $table->index('msisdn', 'idx_session_msisdn');
            $table->index('expiresAt', 'idx_session_expires');
            $table->index('refreshTokenFamily', 'idx_session_refreshFamily');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playerSession');
    }
};
