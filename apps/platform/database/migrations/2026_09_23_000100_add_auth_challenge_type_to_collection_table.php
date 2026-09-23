<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection', function (Blueprint $table) {
            $table->string('authChallengeType', 20)->nullable()->after('providerCollectionId')
                ->comment('INPUT_PIN | INPUT_OTP | REDIRECT_3DS | NONE');
            $table->index('providerCollectionId', 'idx_collection_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('collection', function (Blueprint $table) {
            $table->dropIndex('idx_collection_provider_id');
            $table->dropColumn('authChallengeType');
        });
    }
};
