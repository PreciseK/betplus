<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caches OPay wallet validation. Each lookup is billable, so this is a cost control
        // as much as a latency one (REQ-ID-010).
        Schema::create('nameLookupCache', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 16);
            $table->string('returnedName', 150)->nullable();
            $table->string('lookupStatus', 20)->comment('success | not_found | error');
            $table->json('rawResponse')->nullable();
            $table->dateTime('lookedUpAt')->useCurrent();
            $table->dateTime('expiresAt')->nullable();

            $table->index('msisdn', 'idx_nameLookup_msisdn');
            $table->index('expiresAt', 'idx_nameLookup_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nameLookupCache');
    }
};
