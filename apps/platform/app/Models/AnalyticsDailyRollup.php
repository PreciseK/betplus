<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDailyRollup extends Model
{
    protected $table = 'analyticsDailyRollup';

    const CREATED_AT = null;
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'day' => 'date',
        'computedAt' => 'datetime',
    ];
}
