<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts (REQ-WAL-012). 'scope' disambiguates instances of a type:
        // a player id string for PLAYER_PLAY/PLAYER_WINNINGS, a state code for
        // WHT_PAYABLE/GGR_LEVY_PAYABLE, null for the platform-global singletons
        // (SUSPENSE, HOUSE_REVENUE, PAYMENT_CLEARING_OPAY, OPAY_FLOAT, PRIZE_LIABILITY,
        // FEES, DRAW_TICKET_COST).
        Schema::create('ledgerAccount', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('scope', 20)->nullable();
            $table->string('normalBalance', 6)->comment('debit | credit — the side that increases this account');
            $table->dateTime('createdAt')->useCurrent();

            $table->unique(['type', 'scope'], 'uniq_ledgerAccount_type_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgerAccount');
    }
};
