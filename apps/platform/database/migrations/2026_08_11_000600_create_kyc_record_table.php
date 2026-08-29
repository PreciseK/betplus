<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verification metadata only. The identifiers themselves live in the identity vault
        // behind a separate credential (REQ-ID-023, Story 1.10).
        Schema::create('kycRecord', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();

            $table->string('idType', 10)->comment('nin | bvn');
            $table->string('verificationRef', 100)->nullable()
                ->comment('Identity vendor reference; never the identifier itself');
            $table->string('verificationVendor', 50)->nullable();
            $table->string('verificationMethod', 20)->comment('automated | manual | hybrid');

            // Name from OPay compared against the name from the identity vendor.
            $table->string('opayNameMatch', 20)->default('not_checked')
                ->comment('exact | partial | mismatch | not_checked');

            $table->date('dateOfBirth')->nullable();
            $table->dateTime('verifiedAt')->nullable();
            $table->unsignedBigInteger('verifiedBy')->nullable()
                ->comment('institutionUser.id when manual; FK added with the back office in Epic 6');
            $table->string('rejectionReason', 500)->nullable();

            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('playerId', 'idx_kyc_player');
            $table->index('idType', 'idx_kyc_idType');
            $table->index('verifiedAt', 'idx_kyc_verifiedAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kycRecord');
    }
};
