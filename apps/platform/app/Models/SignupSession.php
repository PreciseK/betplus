<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SignupSession extends Model
{
    protected $table = 'signupSession';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'nameLookupRaw' => 'array',
        'lookupCompletedAt' => 'datetime',
        'otpVerifiedAt' => 'datetime',
        'otpExpiresAt' => 'datetime',
        'consumedAt' => 'datetime',
        'expiresAt' => 'datetime',
    ];

    /**
     * Shared by every step that continues an already-OTP-verified flow.
     *
     * @param Builder<SignupSession> $query
     * @return Builder<SignupSession>
     */
    public function scopeVerifiedAndUnconsumed(Builder $query, string $msisdn): Builder
    {
        return $query->where('msisdn', $msisdn)
            ->whereNotNull('otpVerifiedAt')
            ->whereNull('consumedAt')
            ->latest('id');
    }
}
