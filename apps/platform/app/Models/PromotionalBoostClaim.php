<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionalBoostClaim extends Model
{
    protected $table = 'promotionalBoostClaim';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'originalPrizeKobo' => 'integer',
        'boostBonusKobo' => 'integer',
        'claimedAt' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'playerId');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticketId');
    }
}
