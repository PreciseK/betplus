<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FairnessSeed extends Model
{
    protected $table = 'fairnessSeed';

    const CREATED_AT = 'issuedAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'issuedAt' => 'datetime',
    ];
}
