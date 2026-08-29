<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 5.5/5.6 — REQ-RG-011: "the registry is authoritative; the local table
        // is a cache." Matched by ninHash (REQ-RG-012), the same SHA-256 hash already
        // stored on player.ninHash — never the raw NIN, which stays in the vault.
        // checkedAt drives the fail-closed staleness rule (REQ-RG-013/014): a ticket
        // read uses a cache up to 15 minutes old; beyond 6 hours stale, refuse rather
        // than guess (REGISTRY_UNAVAILABLE).
        Schema::create('registryExclusion', function (Blueprint $table) {
            $table->id();
            $table->string('ninHash', 64)->unique('uniq_registryExclusion_ninHash');
            $table->string('registryName', 100);
            $table->boolean('excluded');
            $table->dateTime('checkedAt');
            $table->dateTime('createdAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registryExclusion');
    }
};
