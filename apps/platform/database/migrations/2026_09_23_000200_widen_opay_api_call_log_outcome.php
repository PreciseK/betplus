<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opayApiCallLog', function (Blueprint $table) {
            $table->string('outcome', 50)
                ->comment('success | failed | unreachable | rejected_*')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('opayApiCallLog', function (Blueprint $table) {
            $table->string('outcome', 20)
                ->comment('success | failed | unreachable')
                ->change();
        });
    }
};
