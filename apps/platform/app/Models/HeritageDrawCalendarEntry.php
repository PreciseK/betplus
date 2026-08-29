<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeritageDrawCalendarEntry extends Model
{
    protected $table = 'heritageDrawCalendarEntry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'scheduledAt' => 'datetime',
        'cutoffAt' => 'datetime',
    ];
}
