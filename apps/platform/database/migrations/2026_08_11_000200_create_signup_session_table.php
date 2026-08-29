<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signupSession', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 16)->comment('E.164 canonical form');

            // Populated by step 1 from OPay wallet validation (REQ-ID-010).
            $table->string('registeredName', 150)->nullable();
            $table->json('nameLookupRaw')->nullable();
            $table->dateTime('lookupCompletedAt')->nullable();
            $table->dateTime('otpVerifiedAt')->nullable();

            $table->string('ipAddress', 45)->nullable();
            $table->dateTime('consumedAt')->nullable()->comment('Set when step 3 creates the player');
            $table->dateTime('expiresAt');
            $table->dateTime('createdAt')->useCurrent();

            $table->index('msisdn', 'idx_signupSession_msisdn');
            $table->index('expiresAt', 'idx_signupSession_expires');
            $table->index('consumedAt', 'idx_signupSession_consumed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signupSession');
    }
};
