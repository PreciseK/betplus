<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewableChange extends Model
{
    protected $table = 'reviewableChange';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'beforeSnapshot' => 'array',
        'submittedAt' => 'datetime',
        'checkerDecisionAt' => 'datetime',
        'appliedAt' => 'datetime',
    ];
}
