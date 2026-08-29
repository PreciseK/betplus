<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NameLookupCache extends Model
{
    protected $table = 'nameLookupCache';

    const CREATED_AT = 'lookedUpAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'rawResponse' => 'array',
        'expiresAt' => 'datetime',
    ];
}
