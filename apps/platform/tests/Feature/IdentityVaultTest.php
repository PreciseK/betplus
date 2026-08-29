<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Vault\IdentityVaultService;
use App\Models\Vault\VaultAccessLog;
use App\Models\Vault\VaultedIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class IdentityVaultTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
    }

    public function test_store_never_persists_the_raw_value_and_returns_a_token(): void
    {
        $token = app(IdentityVaultService::class)->store(1, 'nin', '12345678901', 'IdentityVaultTest');

        $this->assertMatchesRegularExpression('/^vault:\d+$/', $token);

        $row = VaultedIdentifier::first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('12345678901', $row->valueEncrypted);
        $this->assertStringNotContainsString('12345678901', json_encode($row->toArray()));
    }

    public function test_every_store_is_audited(): void
    {
        app(IdentityVaultService::class)->store(7, 'nin', '12345678901', 'IdentityVaultTest');

        $this->assertDatabaseHas('identityVaultAccessLog', [
            'playerId' => 7,
            'action' => 'store',
            'idType' => 'nin',
            'requestedBy' => 'IdentityVaultTest',
        ], 'identity_vault');
    }

    public function test_vault_rows_are_not_visible_on_the_default_connection(): void
    {
        app(IdentityVaultService::class)->store(1, 'nin', '12345678901', 'IdentityVaultTest');

        // The vault lives on a separate connection — the default connection's schema
        // has no vaultedIdentifier table at all (D-06).
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::connection('sqlite')->hasTable('vaultedIdentifier'),
        );
    }

    public function test_alreadyStored_dedupes_without_needing_to_decrypt(): void
    {
        $service = app(IdentityVaultService::class);
        $this->assertFalse($service->alreadyStored('nin', '12345678901'));

        $service->store(1, 'nin', '12345678901', 'IdentityVaultTest');

        $this->assertTrue($service->alreadyStored('nin', '12345678901'));
        $this->assertFalse($service->alreadyStored('bvn', '12345678901'));
    }

    public function test_storing_the_same_identifier_twice_does_not_duplicate_the_row(): void
    {
        $service = app(IdentityVaultService::class);
        $tokenA = $service->store(1, 'nin', '12345678901', 'IdentityVaultTest');
        $tokenB = $service->store(1, 'nin', '12345678901', 'IdentityVaultTest');

        $this->assertSame($tokenA, $tokenB);
        $this->assertSame(1, VaultedIdentifier::count());
        // Still logged as two separate access events even though the row is unchanged.
        $this->assertSame(2, VaultAccessLog::count());
    }
}
