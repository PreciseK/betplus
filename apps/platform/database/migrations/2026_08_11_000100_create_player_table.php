<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player', function (Blueprint $table) {
            $table->id();

            // Identity. E.164 with leading +, e.g. +2348012345678 (REQ-ID-001).
            $table->string('msisdn', 16)->unique('uniq_player_msisdn');

            // Authoritative name from OPay wallet validation. Never player-supplied (REQ-ID-013).
            $table->string('registeredName', 150);
            $table->string('displayName', 100)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('passwordHash')->nullable()->comment('NULL for USSD-only players');
            $table->string('pinHash')->nullable();

            // KYC tiering (REQ-ID-020). 0 = MSISDN + OPay wallet, 1 = + NIN, 2 = + BVN.
            $table->unsignedTinyInteger('kycTier')->default(0);
            $table->string('kycStatus', 20)->default('pending')
                ->comment('pending | verified | rejected | suspended');
            $table->string('accountStatus', 20)->default('active')
                ->comment('active | frozen | closed');

            // Drives withholding rate; derived from KYC, never inferred (REQ-TAX-004).
            $table->string('residencyStatus', 20)->default('resident')
                ->comment('resident | non_resident');

            // Raw NIN/BVN live in the identity vault (Story 1.10). Only a hash and a flag here.
            $table->string('ninHash', 64)->nullable()->comment('SHA-256; raw value never stored here');
            $table->dateTime('bvnVerifiedAt')->nullable();

            $table->string('registrationChannel', 10)->comment('web | app | ussd');
            $table->dateTime('lastLoginAt')->nullable();

            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            // Seven-year retention from last transaction (REQ-ID-025, REQ-DATA-001).
            $table->dateTime('deletedAt')->nullable();

            $table->index('kycStatus', 'idx_player_kycStatus');
            $table->index('accountStatus', 'idx_player_accountStatus');
            $table->index('kycTier', 'idx_player_kycTier');
            $table->index('createdAt', 'idx_player_createdAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player');
    }
};
