<?php

declare(strict_types=1);

namespace App\Domain\Identity\Vault;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts vault values with a key independent of the app's APP_KEY (REQ-ID-023 —
 * "independent access control"). A leaked APP_KEY must not be enough to decrypt these.
 */
final class VaultCipher
{
    private readonly Encrypter $encrypter;
    private readonly string $hmacKey;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('VAULT_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        $this->encrypter = new Encrypter($key, 'aes-256-cbc');
        $this->hmacKey = $key;
    }

    public function encrypt(string $value): string
    {
        return $this->encrypter->encrypt($value);
    }

    public function decrypt(string $encrypted): string
    {
        return $this->encrypter->decrypt($encrypted);
    }

    /** Deterministic, for exact-match lookups without decrypting every row. */
    public function lookupHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hmacKey);
    }
}
