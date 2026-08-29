<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 6.7 (REQ-BO-010, REQ-QA-017, REQ-NFR-043). A separate concept from
        // gameRegistry.enabledStates (Epic 3) — that array is DERIVED from this table
        // (a state only belongs in a game's footprint while it holds a currently-valid
        // licence here), not the other way around. "Play stops at expiry rather than
        // the next deployment" only holds if something reads expiresAt at request time
        // — see LicenceService.
        Schema::create('stateLicence', function (Blueprint $table) {
            $table->id();
            $table->string('stateCode', 10)->unique('uniq_stateLicence_stateCode');
            $table->string('licenceNumber', 100);
            $table->date('issuedAt');
            $table->date('expiresAt');
            $table->string('rulesetVersion', 30);
            $table->string('remittanceStatus', 20)->default('current')->comment('current|overdue|unknown');
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('expiresAt', 'idx_stateLicence_expiresAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stateLicence');
    }
};
