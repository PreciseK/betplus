<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $table = 'player';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'lastLoginAt' => 'datetime',
        'bvnVerifiedAt' => 'datetime',
        'deletedAt' => 'datetime',
    ];
}
