<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolEntry extends Model
{
    protected $table = 'poolEntry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'predictionJson' => 'array',
        'won' => 'boolean',
    ];

    /** @return BelongsTo<PoolDraw, $this> */
    public function poolDraw(): BelongsTo
    {
        return $this->belongsTo(PoolDraw::class, 'poolDrawId');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticketId');
    }
}
