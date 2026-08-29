<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionUser extends Model
{
    protected $table = 'institutionUser';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];
    protected $hidden = ['passwordHash', 'mfaSecretEncrypted'];

    protected $casts = [
        'mfaConfirmedAt' => 'datetime',
        'lastLoginAt' => 'datetime',
    ];
}
