<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionalCampaign extends Model
{
    protected $table = 'promotionalCampaign';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'rulesJson' => 'array',
        'version' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(InstitutionUser::class, 'lastUpdatedBy');
    }
}
