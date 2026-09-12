<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrashRound extends Model
{
    protected $table = 'crashRound';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'bettingStartedAt' => 'datetime',
        'flightStartedAt' => 'datetime',
        'crashedAt' => 'datetime',
    ];

    /** @return HasMany<CrashBet, $this> */
    public function bets(): HasMany
    {
        return $this->hasMany(CrashBet::class, 'roundId');
    }

    /** @return BelongsTo<FairnessSeed, $this> */
    public function fairnessSeed(): BelongsTo
    {
        return $this->belongsTo(FairnessSeed::class, 'fairnessSeedId');
    }
}
