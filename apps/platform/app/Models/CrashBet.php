<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrashBet extends Model
{
    protected $table = 'crashBet';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'autoCashedOut' => 'boolean',
        'settledAt' => 'datetime',
    ];

    /** @return BelongsTo<CrashRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(CrashRound::class, 'roundId');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'playerId');
    }
}
