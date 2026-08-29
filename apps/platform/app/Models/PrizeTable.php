<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrizeTable extends Model
{
    protected $table = 'prizeTable';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'effectiveAt' => 'datetime',
        'publishedAt' => 'datetime',
    ];

    /** @return HasMany<PrizeTableTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(PrizeTableTier::class, 'prizeTableId');
    }

    /** @return HasMany<HeritagePrizeTier, $this> */
    public function heritageTiers(): HasMany
    {
        return $this->hasMany(HeritagePrizeTier::class, 'prizeTableId');
    }
}
