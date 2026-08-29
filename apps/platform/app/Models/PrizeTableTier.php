<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrizeTableTier extends Model
{
    protected $table = 'prizeTableTier';

    const CREATED_AT = null;
    const UPDATED_AT = null;

    protected $guarded = ['id'];
}
