<?php

declare(strict_types=1);

use App\Models\GameRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $floor = GameRegistry::MIN_STAKE_FLOOR_KOBO;
        DB::table('gameRegistry')->where('minStakeKobo', '<', $floor)->update(['minStakeKobo' => $floor]);
        DB::table('gameRegistry')->where('maxStakeKobo', '<', $floor)->update(['maxStakeKobo' => $floor]);
    }

    // Previous per-game minimums are not recorded; a rollback leaves the ₦200 floor in place.
    public function down(): void {}
};
