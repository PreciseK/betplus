<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery records for OTP and transactional messages (REQ-NOT-005).
        Schema::create('smsLog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playerId')->nullable()->constrained('player')->nullOnDelete();
            $table->string('msisdn', 16);
            $table->string('category', 30)
                ->comment('otp | security_alert | system | ... extended per REQ-NOT-003');
            $table->string('templateVersion', 30)->nullable()
                ->comment('Messages are templated and versioned (REQ-NOT-002)');
            $table->string('messageBody', 500);
            $table->string('relatedTable', 50)->nullable();
            $table->unsignedBigInteger('relatedId')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('providerMsgId', 100)->nullable();
            $table->string('status', 12)->default('queued')
                ->comment('queued | sent | delivered | failed');
            $table->string('failureReason', 500)->nullable();
            // Provider cost, not player money. Integer minor unit per project-context rule 1.
            $table->unsignedInteger('costKobo')->nullable();
            $table->dateTime('queuedAt')->useCurrent();
            $table->dateTime('sentAt')->nullable();
            $table->dateTime('deliveredAt')->nullable();

            $table->index('playerId', 'idx_sms_player');
            $table->index('msisdn', 'idx_sms_msisdn');
            $table->index('category', 'idx_sms_category');
            $table->index('status', 'idx_sms_status');
            $table->index(['relatedTable', 'relatedId'], 'idx_sms_related');
            $table->index('queuedAt', 'idx_sms_queuedAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smsLog');
    }
};
