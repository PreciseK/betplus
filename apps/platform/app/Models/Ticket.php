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
}
