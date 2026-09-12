<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrashConfig extends Model
{
    protected $table = 'crashConfig';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'effectiveAt' => 'datetime',
        'publishedAt' => 'datetime',
    ];
}
