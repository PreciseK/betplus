<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeritageCatalogueItem extends Model
{
    protected $table = 'heritageCatalogueItem';

    protected $primaryKey = 'itemNumber';
    public $incrementing = false;
    protected $keyType = 'int';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'publishedAt' => 'datetime',
    ];
}
