<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotionalCampaign', function (Blueprint $table) {
            $table->id();
            $table->string('campaignKey', 64)->unique('uniq_promotionalCampaign_key');
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('DISABLED')->comment('ENABLED | DISABLED');
            $table->json('rulesJson')->comment('Config parameters, budget limits, caps, thresholds');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('lastUpdatedBy')->nullable()->constrained('institutionUser')->nullOnDelete();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('createdAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotionalCampaign');
    }
};
