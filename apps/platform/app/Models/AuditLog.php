<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'auditLog';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}
