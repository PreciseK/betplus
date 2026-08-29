<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $table = 'smsLog';

    const CREATED_AT = 'queuedAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'sentAt' => 'datetime',
        'deliveredAt' => 'datetime',
    ];
}
