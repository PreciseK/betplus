<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $table = 'payout';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'manualReviewRequired' => 'boolean',
        'dispatchedAt' => 'datetime',
        'confirmedAt' => 'datetime',
    ];
}
