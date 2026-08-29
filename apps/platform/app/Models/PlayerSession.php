<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerSession extends Model
{
    protected $table = 'playerSession';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'expiresAt' => 'datetime',
        'terminatedAt' => 'datetime',
    ];
}
