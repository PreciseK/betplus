<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KycRecord;
use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class NinVerificationTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
    }

    private function signedInPlayer(string $msisdn = '+2348031234567'): array
    {
        $player = Player::create(['msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web']);
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

    public function test_adult_with_valid_nin_reaches_tier_1(): void
    {
        [$player, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => '1990-05-20',
            'nin' => '12345678901',
        ]);

        $response->assertOk()->assertJson(['status' => 'verified_tier_1']);
        $this->assertSame(1, $player->fresh()->kycTier);
        $this->assertDatabaseHas('kycRecord', ['playerId' => $player->id, 'idType' => 'nin']);

        $record = KycRecord::first();
        $this->assertNotNull($record->verifiedAt);
        $this->assertNotNull($record->verificationRef);
        $this->assertStringStartsWith('vault:', $record->verificationRef);
        // The raw NIN is never in this row or anywhere on the default connection.
        $this->assertStringNotContainsString('12345678901', json_encode($record->toArray()));
    }

    public function test_under_18_is_frozen_and_blocked_not_upgraded(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $under18Dob = now()->subYears(17)->format('Y-m-d');

        $response = $this->withToken($token)->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => $under18Dob,
            'nin' => '12345678901',
        ]);

        $response->assertOk()->assertJson(['status' => 'under_age']);
        $fresh = $player->fresh();
        $this->assertSame(0, $fresh->kycTier);
        $this->assertSame('frozen', $fresh->accountStatus);
        $this->assertDatabaseHas('auditLog', ['targetTable' => 'player', 'targetId' => $player->id]);
    }

    public function test_request_without_a_token_is_rejected(): void
    {
        $response = $this->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => '1990-05-20',
            'nin' => '12345678901',
        ]);

        $response->assertStatus(401);
    }

    public function test_malformed_nin_is_rejected_by_validation(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => '1990-05-20',
            'nin' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_expired_access_token_is_rejected(): void
    {
        [, $token] = $this->signedInPlayer();
        Cache::forget("access-token:$token");

        $response = $this->withToken($token)->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => '1990-05-20',
            'nin' => '12345678901',
        ]);

        $response->assertStatus(401);
    }
}
