<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerPromotionalMilestone extends Model
{
    protected $table = 'playerPromotionalMilestone';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'roundsCompleted' => 'integer',
        'targetRounds' => 'integer',
        'bonusAwardedKobo' => 'integer',
        'isAwarded' => 'boolean',
        'awardedAt' => 'datetime',
        'bonusExpiresAt' => 'datetime',
        'windowStart' => 'datetime',
        'windowEnd' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'playerId');
    }
}
