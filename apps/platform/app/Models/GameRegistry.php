<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRegistry extends Model
{
    protected $table = 'gameRegistry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    /** Platform-wide minimum stake (₦200). Operators may raise a game's minimum, never lower it below this. */
    public const MIN_STAKE_FLOOR_KOBO = 20_000;

    protected $guarded = ['id'];

    protected $casts = [
        'enabledChannels' => 'array',
        'enabledStates' => 'array',
    ];
}
