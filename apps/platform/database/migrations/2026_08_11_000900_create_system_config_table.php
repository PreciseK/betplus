<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational runtime configuration. Prize tables, tax rates and jurisdiction rulesets
        // get their own versioned, effective-dated tables under maker-checker.
        Schema::create('systemConfig', function (Blueprint $table) {
            $table->id();
            $table->string('configKey', 100)->unique('uniq_config_key');
            $table->string('configValue', 1000);
            $table->string('valueType', 12)->default('string')
                ->comment('integer | decimal | string | boolean | json');
            $table->string('description', 500)->nullable();
            $table->string('category', 50)->nullable();
            $table->boolean('editableViaAdmin')->default(true);
            $table->unsignedBigInteger('lastChangedBy')->nullable()
                ->comment('institutionUser.id; FK added with the back office in Epic 6');
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('category', 'idx_config_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('systemConfig');
    }
};
