<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passwordResetRequest', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('tokenHash');
            $table->string('deliveryChannel', 10)->comment('sms | email | ussd');
            $table->string('requestIp', 45)->nullable();
            $table->string('requestUserAgent', 500)->nullable();
            $table->string('status', 20)->default('pending')
                ->comment('pending | used | expired | revoked');
            $table->dateTime('requestedAt')->useCurrent();
            $table->dateTime('expiresAt');
            $table->dateTime('usedAt')->nullable();
            $table->string('usedFromIp', 45)->nullable();

            $table->index('playerId', 'idx_pwreset_player');
            $table->index('status', 'idx_pwreset_status');
            $table->index('expiresAt', 'idx_pwreset_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passwordResetRequest');
    }
};
