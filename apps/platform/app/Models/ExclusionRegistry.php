<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExclusionRegistry extends Model
{
    protected $table = 'exclusionRegistry';

    const CREATED_AT = 'configuredAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'configuredAt' => 'datetime',
    ];
}
