<?php

declare(strict_types=1);

namespace App\Models\Vault;

use Illuminate\Database\Eloquent\Model;

/**
 * Lives on the identity_vault connection, never the default one. Only
 * App\Domain\Identity\Vault\IdentityVaultService may touch this model.
 */
class VaultedIdentifier extends Model
{
    protected $connection = 'identity_vault';
    protected $table = 'vaultedIdentifier';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];
}
