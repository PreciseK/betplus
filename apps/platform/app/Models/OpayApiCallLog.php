<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpayApiCallLog extends Model
{
    protected $table = 'opayApiCallLog';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'requestBody' => 'array',
        'responseBody' => 'array',
    ];
}
