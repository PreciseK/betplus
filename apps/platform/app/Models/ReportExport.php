<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    protected $table = 'reportExport';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'filter' => 'array',
        'expiresAt' => 'datetime',
        'completedAt' => 'datetime',
    ];
}
