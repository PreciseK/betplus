<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable — enforced at the DB level by triggers (see the creating migration). */
class LedgerEntry extends Model
{
    protected $table = 'ledgerEntry';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];
}
