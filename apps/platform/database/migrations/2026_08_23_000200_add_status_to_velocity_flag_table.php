<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // velocityFlag had no way to tell "needs a decision" from "already actioned" —
        // every row was permanently open per the schema. The back-office review queue
        // (REQ-RG-020/021's "Epic 6 back office" the original migration comment pointed
        // at) needs this to distinguish the two.
        Schema::table('velocityFlag', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('detail')->comment('open|resolved');
            $table->dateTime('resolvedAt')->nullable()->after('status');
            $table->foreignId('resolvedByInstitutionUserId')->nullable()->after('resolvedAt')
                ->constrained('institutionUser')->nullOnDelete();

            $table->index('status', 'idx_velocityFlag_status');
        });
    }

    public function down(): void
    {
        Schema::table('velocityFlag', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolvedByInstitutionUserId');
            $table->dropColumn(['status', 'resolvedAt']);
        });
    }
};
