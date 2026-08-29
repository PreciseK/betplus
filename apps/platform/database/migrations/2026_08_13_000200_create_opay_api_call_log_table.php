<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable log of every OPay request/response (REQ-PAY-019). No update path —
        // rows are write-once from OpayGateway.
        Schema::create('opayApiCallLog', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 150);
            $table->string('signatureScheme', 20)->comment('hmac_sha512 | rsa_sha256');
            $table->string('reference', 100)->nullable()->comment('Betplus-generated reference, if any');
            $table->unsignedSmallInteger('httpStatus')->nullable();
            $table->string('opayCode', 10)->nullable()->comment('OPay response "code", e.g. 00000');
            $table->string('outcome', 20)->comment('success | failed | unreachable');
            $table->unsignedInteger('latencyMs');
            $table->json('requestBody')->nullable();
            $table->json('responseBody')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->index('endpoint', 'idx_opayLog_endpoint');
            $table->index('reference', 'idx_opayLog_reference');
            $table->index('createdAt', 'idx_opayLog_createdAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opayApiCallLog');
    }
};
