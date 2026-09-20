<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Self-contained inside the vault schema — a compromise of the main app database must
    // not be able to erase evidence of vault access (REQ-ID-023 "every access is audited").
    protected $connection = 'identity_vault';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('identityVaultAccessLog')) {
            return;
        }

        Schema::connection($this->connection)->create('identityVaultAccessLog', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('playerId')->nullable();
            $table->string('action', 20)->comment('store | read');
            $table->string('idType', 10)->comment('nin | bvn');
            $table->string('requestedBy', 100)->comment('Calling service/class — never a human-entered reason');
            $table->dateTime('createdAt')->useCurrent();

            $table->index('playerId', 'idx_vaultLog_player');
            $table->index('createdAt', 'idx_vaultLog_createdAt');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('identityVaultAccessLog');
    }
};
