<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StateLicence extends Model
{
    protected $table = 'stateLicence';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'issuedAt' => 'date',
        'expiresAt' => 'date',
    ];
}
