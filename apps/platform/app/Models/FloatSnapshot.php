<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloatSnapshot extends Model
{
    protected $table = 'floatSnapshot';

    const CREATED_AT = 'polledAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'polledAt' => 'datetime',
        'opayBalanceKobo' => 'integer',
    ];
}
