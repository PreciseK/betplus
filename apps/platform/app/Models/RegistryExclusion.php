<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistryExclusion extends Model
{
    protected $table = 'registryExclusion';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'excluded' => 'boolean',
        'checkedAt' => 'datetime',
    ];
}
