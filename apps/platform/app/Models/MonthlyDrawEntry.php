<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyDrawEntry extends Model
{
    protected $table = 'monthlyDrawEntry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'turnoverKobo' => 'integer',
        'ticketCount' => 'integer',
        'ticketRangeStart' => 'integer',
        'ticketRangeEnd' => 'integer',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(MonthlyDrawPool::class, 'poolId');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'playerId');
    }
}
