<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signupSession', function (Blueprint $table) {
            $table->string('mobileNetwork', 16)->nullable();
        });
        Schema::table('player', function (Blueprint $table) {
            $table->string('mobileNetwork', 16)->nullable()
                ->comment('Player-selected network: mtn | glo | airtel | t2; NULL when not recorded');
            $table->index('mobileNetwork', 'idx_player_mobileNetwork');
        });
    }

    public function down(): void
    {
        Schema::table('player', function (Blueprint $table) {
            $table->dropIndex('idx_player_mobileNetwork');
            $table->dropColumn('mobileNetwork');
        });
        Schema::table('signupSession', function (Blueprint $table) {
            $table->dropColumn('mobileNetwork');
        });
    }
};
