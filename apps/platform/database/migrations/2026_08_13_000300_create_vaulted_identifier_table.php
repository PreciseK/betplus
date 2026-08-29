<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runs against the identity_vault connection regardless of the default connection
    // migrate is invoked with — this is what keeps it out of the main schema (D-06).
    protected $connection = 'identity_vault';

    public function up(): void
    {
        Schema::connection($this->connection)->create('vaultedIdentifier', function (Blueprint $table) {
            $table->id();
            // No FK to player — that table lives in a different schema/connection entirely.
            $table->unsignedBigInteger('playerId');
            $table->string('idType', 10)->comment('nin | bvn');

            // The only place the raw identifier is ever stored, encrypted with a key that
            // is never the app's APP_KEY (see VaultCipher) — a leaked APP_KEY alone must
            // not be enough to decrypt these.
            $table->text('valueEncrypted');
            // Deterministic HMAC for exact-match lookups (e.g. dedup) without decrypting.
            $table->string('lookupHash', 64);

            $table->dateTime('createdAt')->useCurrent();

            $table->index('playerId', 'idx_vault_player');
            $table->unique(['idType', 'lookupHash'], 'uniq_vault_idType_lookupHash');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('vaultedIdentifier');
    }
};
