<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    protected $table = 'ticket';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'predictionJson' => 'array',
        'attributionConfidence' => 'decimal:3',
        'revealedAt' => 'datetime',
        'createdAt' => 'datetime',
    ];

    /** @return HasOne<TicketOutcome, $this> */
    public function outcome(): HasOne
    {
        return $this->hasOne(TicketOutcome::class, 'ticketId');
    }

    /**
     * Model 4 only — a PENDING_DRAW ticket's row in the pool it joined.
     *
     * @return HasOne<PoolEntry, $this>
     */
    public function poolEntry(): HasOne
    {
        return $this->hasOne(PoolEntry::class, 'ticketId');
    }
}
