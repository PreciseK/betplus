<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlayerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function signedInToken(string $msisdn = '+2348031234567'): string
    {
        Player::create(['msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web', 'kycTier' => 1]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);

        return $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');
    }

    public function test_me_returns_the_callers_own_profile(): void
    {
        $token = $this->signedInToken();

        $response = $this->withToken($token)->getJson('/v1/me');

        $response->assertOk()->assertJson([
            'registered_name' => 'Ada Okafor',
            'msisdn' => '+2348031234567',
            'kyc_tier' => 1,
            'account_status' => 'active',
        ]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/v1/me')->assertStatus(401);
    }
}
