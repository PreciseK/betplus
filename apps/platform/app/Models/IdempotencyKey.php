<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_key';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'responseHeaders' => 'array',
        'createdAt' => 'datetime',
        'expiresAt' => 'datetime',
    ];
}
