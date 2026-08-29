<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRegistry extends Model
{
    protected $table = 'gameRegistry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'enabledChannels' => 'array',
        'enabledStates' => 'array',
    ];
}
