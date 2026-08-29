<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerLimit extends Model
{
    protected $table = 'playerLimit';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'pendingEffectiveAt' => 'datetime',
    ];
}
