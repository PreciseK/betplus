<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyDrawPool extends Model
{
    protected $table = 'monthlyDrawPool';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'totalTurnoverKobo' => 'integer',
        'allocatedPrizePoolKobo' => 'integer',
        'totalTicketsIssued' => 'integer',
        'winnersJson' => 'array',
        'drawnAt' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(MonthlyDrawEntry::class, 'poolId');
    }
}
