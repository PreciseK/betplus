<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KycRecord;
use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class BvnVerificationTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
    }

    private function signedInPlayer(int $kycTier = 1, string $msisdn = '+2348031234567'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn,
            'registeredName' => 'Ada Okafor',
            'registrationChannel' => 'web',
            'kycTier' => $kycTier,
        ]);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
        $tokens = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json();

        return [$player, $tokens['access_token']];
    }

    public function test_tier_1_player_with_valid_bvn_reaches_tier_2(): void
    {
        [$player, $token] = $this->signedInPlayer(kycTier: 1);

        $response = $this->withToken($token)->postJson('/v1/identity/verify-bvn', ['bvn' => '12345678901']);

        $response->assertOk()->assertJson(['status' => 'verified_tier_2']);
        $this->assertSame(2, $player->fresh()->kycTier);

        $record = KycRecord::where('idType', 'bvn')->first();
        $this->assertNotNull($record->verifiedAt);
        $this->assertStringStartsWith('vault:', $record->verificationRef);
        $this->assertStringNotContainsString('12345678901', json_encode($record->toArray()));
    }

    public function test_tier_0_player_cannot_verify_bvn(): void
    {
        [, $token] = $this->signedInPlayer(kycTier: 0);

        $response = $this->withToken($token)->postJson('/v1/identity/verify-bvn', ['bvn' => '12345678901']);

        $response->assertOk()->assertJson(['status' => 'tier_1_required']);
    }

    public function test_malformed_bvn_is_rejected_by_validation(): void
    {
        [, $token] = $this->signedInPlayer(kycTier: 1);

        $response = $this->withToken($token)->postJson('/v1/identity/verify-bvn', ['bvn' => '123']);

        $response->assertStatus(422);
    }

    public function test_request_without_a_token_is_rejected(): void
    {
        $response = $this->postJson('/v1/identity/verify-bvn', ['bvn' => '12345678901']);

        $response->assertStatus(401);
    }
}
