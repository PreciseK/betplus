<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BirdEscape was the working name of the Caged crash game. Renames its game code in
 * every table that stores one, so history and new play share the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('BIRDESCAPE', 'CAGED_CRASH', 'birdescape-1.0.0', 'caged-crash-1.0.0');
    }

    public function down(): void
    {
        $this->rename('CAGED_CRASH', 'BIRDESCAPE', 'caged-crash-1.0.0', 'birdescape-1.0.0');
    }

    private function rename(string $from, string $to, string $fromEngine, string $toEngine): void
    {
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            if (Schema::hasColumn($table, 'gameCode')) {
                DB::table($table)->where('gameCode', $from)->update(['gameCode' => $to]);
            }
        }
        DB::table('gameRegistry')->where('gameCode', $to)->where('engineVersion', $fromEngine)->update(['engineVersion' => $toEngine]);
    }
};
