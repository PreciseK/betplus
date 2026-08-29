<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerProtectionEvent extends Model
{
    protected $table = 'playerProtectionEvent';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'startedAt' => 'datetime',
        'endsAt' => 'datetime',
    ];
}
