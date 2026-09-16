<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CreateDepositRequest validates `reference` up to 64 chars (it's a
        // client-generated idempotency key — crypto.randomUUID() alone is 36), but
        // this column was left at 32 from the original OTP-based Collection design.
        // Every real web/app deposit would have failed to insert.
        //
        // Also refreshes `status`'s comment: FundingService::collect() no longer uses
        // pending_otp/processing/unknown (the OTP Collection flow they described is
        // gone) and now writes pending_review for a deposit held above
        // config('funding.manual_review_threshold_kobo').
        Schema::table('collection', function (Blueprint $table) {
            $table->string('reference', 64)->change();
            $table->string('status', 20)->default('paid')
                ->comment('paid | pending_review | failed')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('collection', function (Blueprint $table) {
            $table->string('reference', 32)->change();
            $table->string('status', 20)->default('pending_otp')
                ->comment('pending_otp | processing | paid | failed | unknown')
                ->change();
        });
    }
};
