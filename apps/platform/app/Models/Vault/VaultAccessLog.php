<?php

declare(strict_types=1);

namespace App\Models\Vault;

use Illuminate\Database\Eloquent\Model;

class VaultAccessLog extends Model
{
    protected $connection = 'identity_vault';
    protected $table = 'identityVaultAccessLog';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];
}
