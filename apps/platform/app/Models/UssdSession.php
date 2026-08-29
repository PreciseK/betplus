<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UssdSession extends Model
{
    protected $table = 'ussdSession';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'lastTurnAt' => 'datetime',
    ];
}
