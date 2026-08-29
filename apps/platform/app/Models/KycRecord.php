<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycRecord extends Model
{
    protected $table = 'kycRecord';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'dateOfBirth' => 'date',
        'verifiedAt' => 'datetime',
    ];
}
