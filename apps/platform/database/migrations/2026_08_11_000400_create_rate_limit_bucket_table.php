<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fallback store. Redis is the primary rate-limit backing store (architecture D-03);
        // this table keeps limits durable across a Redis flush and auditable afterwards.
        Schema::create('rateLimitBucket', function (Blueprint $table) {
            $table->id();
            $table->string('bucketKey', 150);
            $table->dateTime('windowStart');
            $table->unsignedInteger('attempts')->default(1);
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['bucketKey', 'windowStart'], 'uniq_rateLimit_bucket_window');
            $table->index('windowStart', 'idx_rateLimit_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rateLimitBucket');
    }
};
