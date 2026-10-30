<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoorsRound extends Model
{
    protected $table = 'doorsRound';
    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;
    protected $guarded = ['id'];
    protected $hidden = ['boardEncrypted', 'configJson'];
    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $casts = [
        'boardEncrypted' => 'encrypted:array', 'openedJson' => 'array', 'configJson' => 'array',
        'expiresAt' => 'datetime', 'settledAt' => 'datetime', 'createdAt' => 'datetime', 'exposureKobo' => 'integer',
    ];
    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class, 'ticketId'); }
}
