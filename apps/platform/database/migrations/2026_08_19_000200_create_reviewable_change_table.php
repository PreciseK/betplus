<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 6.3 (REQ-BO-003/015..018) — the ONE shared maker-checker workflow every
        // reviewable change type moves through. Adding a new reviewable action means
        // registering a changeType + an applier (MakerCheckerService), never a bespoke
        // approval path (REQ-BO-015's own stated design goal).
        Schema::create('reviewableChange', function (Blueprint $table) {
            $table->id();
            $table->string('changeType', 40)->comment('prize_table_publish|game_registry_update|manual_credit_debit|jurisdiction_ruleset_change|tax_rate_change|float_topup_recording|limit_override|catalogue_publish|manual_payout_approval');
            $table->string('status', 20)->default('DRAFT')->comment('DRAFT|AWAITING_APPROVAL|APPROVED|REJECTED|APPLIED');

            $table->json('payload')->comment('the proposed change — shape is changeType-specific');
            $table->json('beforeSnapshot')->nullable()->comment('state immediately before, for the maker/checker diff view');

            $table->foreignId('makerId')->constrained('institutionUser')->restrictOnDelete();
            $table->string('makerJustification', 1000);
            $table->dateTime('submittedAt')->nullable();

            $table->foreignId('checkerId')->nullable()->constrained('institutionUser')->restrictOnDelete();
            $table->dateTime('checkerDecisionAt')->nullable();
            $table->string('rejectionReason', 1000)->nullable();

            $table->dateTime('appliedAt')->nullable();

            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('changeType', 'idx_reviewableChange_changeType');
            $table->index('status', 'idx_reviewableChange_status');
            $table->index('makerId', 'idx_reviewableChange_maker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewableChange');
    }
};
