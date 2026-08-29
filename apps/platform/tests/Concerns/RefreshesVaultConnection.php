<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * RefreshDatabase only migrates the default connection, and Laravel's migration tracking
 * table is shared across connections — so a second `artisan('migrate')` call sees the
 * vault migrations already marked "ran" (from RefreshDatabase's own pass) and skips them,
 * even though the vault's :memory: db is fresh and empty. Run the vault migrations'
 * up() directly instead, guarded by hasTable() so it's idempotent within a test run.
 *
 * Deliberately NOT a setUp() override: a class that defines its own setUp() silently
 * replaces a trait's same-named method (no error, no warning) rather than composing
 * with it — call refreshVaultConnection() explicitly from your own setUp() instead.
 */
trait RefreshesVaultConnection
{
    protected function refreshVaultConnection(): void
    {
        $schema = Schema::connection('identity_vault');

        if (!$schema->hasTable('vaultedIdentifier')) {
            (require database_path('migrations/2026_08_13_000300_create_vaulted_identifier_table.php'))->up();
        }
        if (!$schema->hasTable('identityVaultAccessLog')) {
            (require database_path('migrations/2026_08_13_000400_create_identity_vault_access_log_table.php'))->up();
        }
    }
}
