<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks an OPay BankAccount collection (Story 2.3/2.4). `reference` is the
        // Betplus-generated idempotency key (REQ-PAY-012, capped 32 digits) — every write
        // path here is keyed on it so a replay can't double-create or double-credit.
        Schema::create('collection', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('reference', 32)->unique('uniq_collection_reference');
            $table->unsignedBigInteger('amountKobo');
            $table->unsignedBigInteger('feeKobo')->default(0);
            $table->string('providerCollectionId', 100)->nullable()->comment('OPay orderNo, once known');
            $table->string('status', 20)->default('pending_otp')
                ->comment('pending_otp | processing | paid | failed | unknown');
            $table->dateTime('paidAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('playerId', 'idx_collection_player');
            $table->index('status', 'idx_collection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection');
    }
};
