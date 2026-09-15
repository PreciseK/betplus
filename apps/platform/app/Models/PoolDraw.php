<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoolDraw extends Model
{
    protected $table = 'poolDraw';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'drawnOutcomeJson' => 'array',
        'tierRolloverJson' => 'array',
        'opensAt' => 'datetime',
        'closesAt' => 'datetime',
        'drawnAt' => 'datetime',
        'settledAt' => 'datetime',
    ];

    /** @return HasMany<PoolEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PoolEntry::class, 'poolDrawId');
    }
}
