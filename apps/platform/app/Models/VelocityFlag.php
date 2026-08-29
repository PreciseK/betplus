<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VelocityFlag extends Model
{
    protected $table = 'velocityFlag';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'resolvedAt' => 'datetime',
    ];
}
