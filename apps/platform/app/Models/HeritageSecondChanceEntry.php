<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeritageSecondChanceEntry extends Model
{
    protected $table = 'heritageSecondChanceEntry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'selectedNumbers' => 'array',
        'resultJson' => 'array',
        'submittedAt' => 'datetime',
        'confirmedAt' => 'datetime',
        'resultNotifiedAt' => 'datetime',
    ];
}
