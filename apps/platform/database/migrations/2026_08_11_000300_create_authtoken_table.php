<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OTP and verification codes. Normalised from the source schema, which used snake_case,
        // an INT primary key, and expiry split across separate DATE and TIME columns.
        Schema::create('authtoken', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 16);
            $table->string('codeHash');
            $table->string('purpose', 30)->default('otp')
                ->comment('otp | password_reset | step_up');
            $table->dateTime('expiresAt');
            $table->dateTime('consumedAt')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ipAddress', 45)->nullable();
            $table->string('deviceId', 100)->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index('msisdn', 'idx_authtoken_msisdn');
            $table->index('expiresAt', 'idx_authtoken_expires');
            $table->index(['msisdn', 'purpose'], 'idx_authtoken_msisdn_purpose');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authtoken');
    }
};
