<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signupSession', function (Blueprint $table) {
            // Story 1.7: OTP state (REQ-ID-003 — 6 digits, 5-minute TTL, max 5 verify attempts).
            $table->string('otpHash', 64)->nullable()->after('msisdn');
            $table->dateTime('otpExpiresAt')->nullable()->after('otpHash');
            $table->unsignedTinyInteger('otpAttempts')->default(0)->after('otpExpiresAt');
        });
    }

    public function down(): void
    {
        Schema::table('signupSession', function (Blueprint $table) {
            $table->dropColumn(['otpHash', 'otpExpiresAt', 'otpAttempts']);
        });
    }
};
