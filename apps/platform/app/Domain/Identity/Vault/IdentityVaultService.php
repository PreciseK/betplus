<?php

declare(strict_types=1);

namespace App\Domain\Identity\Vault;

use App\Models\Vault\VaultAccessLog;
use App\Models\Vault\VaultedIdentifier;

/**
 * The only class permitted to read or write a raw NIN/BVN (REQ-ID-023, D-06). Everything
 * else in the app deals only in the token this returns.
 */
final class IdentityVaultService
{
    public function __construct(private readonly VaultCipher $cipher)
    {
    }

    /** @return string Opaque token — store this, never the raw value. */
    public function store(int $playerId, string $idType, string $rawValue, string $requestedBy): string
    {
        $hash = $this->cipher->lookupHash($rawValue);

        $record = VaultedIdentifier::firstOrCreate(
            ['idType' => $idType, 'lookupHash' => $hash],
            ['playerId' => $playerId, 'valueEncrypted' => $this->cipher->encrypt($rawValue)],
        );

        VaultAccessLog::create([
            'playerId' => $playerId,
            'action' => 'store',
            'idType' => $idType,
            'requestedBy' => $requestedBy,
        ]);

        return "vault:{$record->id}";
    }

    /** Dedup check without decrypting anything. */
    public function alreadyStored(string $idType, string $rawValue): bool
    {
        return VaultedIdentifier::where('idType', $idType)
            ->where('lookupHash', $this->cipher->lookupHash($rawValue))
            ->exists();
    }

    /**
     * Decrypts the raw value back out — needed because OPay's BankAccount collection
     * method (REQ-PAY-004) requires the BVN on every request, not just once. Every call
     * is audited (REQ-ID-023); $requestedBy should be a class name, never a free-text
     * "reason" a caller could use to explain away an inappropriate access.
     */
    public function retrieve(string $token, string $requestedBy): ?string
    {
        $id = str_starts_with($token, 'vault:') ? (int) substr($token, 6) : null;
        $record = $id !== null ? VaultedIdentifier::find($id) : null;
        if ($record === null) {
            return null;
        }

        VaultAccessLog::create([
            'playerId' => $record->playerId,
            'action' => 'read',
            'idType' => $record->idType,
            'requestedBy' => $requestedBy,
        ]);

        return $this->cipher->decrypt($record->valueEncrypted);
    }
}
