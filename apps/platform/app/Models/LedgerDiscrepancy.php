<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerDiscrepancy extends Model
{
    protected $table = 'ledgerDiscrepancy';

    const CREATED_AT = 'detectedAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'resolvedAt' => 'datetime',
    ];
}
