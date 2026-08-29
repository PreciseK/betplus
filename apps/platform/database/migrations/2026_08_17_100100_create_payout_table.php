<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §7.5 Payout Engine. Covers both automatic prize dispatch (Story 4.1, ticketId
        // set) and player-initiated withdrawal (Story 4.6, ticketId null). The unique
        // constraint on (ticketId, payoutType) is Story 4.4's structural duplicate-
        // payout prevention (REQ-PO-010) — partial (nullable ticketId), so it only
        // constrains automatic-prize rows; withdrawals aren't tied to a ticket and rely
        // on the withdrawal-quote flow instead (a quote is single-use, checked in
        // PayoutService).
        Schema::create('payout', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique('uniq_payout_reference');
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->foreignId('ticketId')->nullable()->constrained('ticket')->restrictOnDelete();
            $table->string('kind', 20)->comment('automatic-prize | withdrawal');
            $table->string('payoutType', 20)->default('OpayWalletNg')->comment('REQ-PO-002 — the only type Betplus uses');
            $table->unsignedBigInteger('amountKobo');
            $table->string('sourceLabel', 100);
            $table->string('destinationPhone', 16);
            $table->string('destinationLabel', 100);
            $table->string('providerStatus', 30)->default('QUEUED')
                ->comment('QUEUED|FLOAT_HALTED then OPay\'s INITIAL|PENDING|CHECKING|SUCCESS|FAIL|CLOSE|RETURN, or an unrecognised code (REQ-PO-006)');
            $table->boolean('manualReviewRequired')->default(false);
            $table->string('opayOrderNo', 60)->nullable();
            $table->dateTime('dispatchedAt')->nullable();
            $table->dateTime('confirmedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['ticketId', 'payoutType'], 'uniq_payout_ticket_payoutType');
            $table->index('playerId', 'idx_payout_player');
            $table->index('providerStatus', 'idx_payout_providerStatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout');
    }
};
