<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 3.5's licensing gate only: "a state cannot enter the active licence
        // footprint until an exclusion registry is configured for it" (REQ-RG-010,
        // REQ-COMP-051). This table records WHICH states have a registry integration
        // configured — it is not the per-player exclusion list itself (REQ-RG-011..016),
        // which is Epic 5 (5-5/5-6) and does not exist yet. Until Epic 5 ships, every
        // ticket creation still passes this state-level gate but performs no per-player
        // registry check — see AttributionService's doc comment.
        Schema::create('exclusionRegistry', function (Blueprint $table) {
            $table->id();
            $table->string('stateCode', 10)->unique('uniq_exclusionRegistry_stateCode');
            $table->string('registryName', 100)->comment('e.g. SafePlay Lagos');
            $table->dateTime('configuredAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusionRegistry');
    }
};
