<?php

declare(strict_types=1);

namespace App\Domain\BackOffice;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts institution-user TOTP secrets with a key independent of both APP_KEY and
 * the identity vault's key (Domain/Identity/Vault/VaultCipher) — a leaked MFA secret
 * store shouldn't imply a leaked NIN/BVN vault or vice versa; different threat model,
 * different rotation schedule, deliberately not the same key.
 */
final class MfaSecretCipher
{
    private readonly Encrypter $encrypter;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKOFFICE_MFA_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        $this->encrypter = new Encrypter($key, 'aes-256-cbc');
    }

    public function encrypt(string $value): string
    {
        return $this->encrypter->encrypt($value);
    }

    public function decrypt(string $encrypted): string
    {
        return $this->encrypter->decrypt($encrypted);
    }
}
