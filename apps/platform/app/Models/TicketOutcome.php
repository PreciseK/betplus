<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketOutcome extends Model
{
    protected $table = 'ticketOutcome';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'resultJson' => 'array',
        'won' => 'boolean',
    ];
}
