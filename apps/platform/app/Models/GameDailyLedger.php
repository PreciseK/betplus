<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDailyLedger extends Model
{
    protected $table = 'gameDailyLedger';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        // Not cast to 'date' — GameDailyLedgerService always reads/writes it as a
        // plain 'Y-m-d' string (now()->toDateString()), and the 'date' cast
        // round-trips through a full datetime string that breaks a plain-string
        // WHERE match against it.
        'grossStakesKobo' => 'integer',
        'grossPrizesKobo' => 'integer',
    ];
}
