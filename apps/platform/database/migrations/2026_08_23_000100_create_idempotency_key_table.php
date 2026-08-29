<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_key', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('playerId')->nullable()->index('idx_idempotency_player');
            $table->string('key', 120);
            $table->string('requestHash', 64)->comment('SHA-256 hash of HTTP method, path and normalized request payload');
            $table->string('status', 20)->default('in_progress')->comment('in_progress | completed | failed');
            $table->unsignedSmallInteger('responseCode')->nullable();
            $table->longText('responseBody')->nullable();
            $table->json('responseHeaders')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('expiresAt')->index('idx_idempotency_expires');

            $table->unique(['playerId', 'key'], 'uniq_player_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_key');
    }
};
