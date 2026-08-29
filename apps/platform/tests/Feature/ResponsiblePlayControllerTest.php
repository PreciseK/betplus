<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResponsiblePlayControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(): array
    {
        $msisdn = '+2348055' . random_int(100000, 999999);
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', (string) random_int(10000000000, 99999999999)),
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

    public function test_the_snapshot_reports_active_status_and_default_limits(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->getJson('/v1/responsible-play');

        $response->assertOk();
        $response->assertJson(['status' => 'active', 'withdrawal_available' => true]);
        $this->assertCount(6, $response->json('limits'));
        $this->assertCount(3, $response->json('cool_off_options'));
        $this->assertCount(3, $response->json('self_exclusion_options'));
    }

    public function test_updating_a_limit_downward_reflects_immediately_in_the_snapshot(): void
    {
        [, $token] = $this->signedInPlayer();

        $update = $this->withToken($token)->postJson('/v1/responsible-play/limits', ['key' => 'stake-daily', 'value' => 100_000]);
        $update->assertOk();
        $update->assertJson(['key' => 'stake-daily', 'current_value' => 100_000]);

        $snapshot = $this->withToken($token)->getJson('/v1/responsible-play')->json();
        $stakeLimit = collect($snapshot['limits'])->firstWhere('key', 'stake-daily');
        $this->assertSame(100_000, $stakeLimit['current_value']);
    }

    public function test_starting_a_cool_off_changes_the_reported_status(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/responsible-play/cool-off', ['option_id' => 'cool-off-24h']);
        $response->assertOk();
        $response->assertJson(['status' => 'cool-off']);

        $snapshot = $this->withToken($token)->getJson('/v1/responsible-play')->json();
        $this->assertSame('cool-off', $snapshot['status']);
        $this->assertNotNull($snapshot['status_ends_at']);
    }

    public function test_self_excluding_changes_the_reported_status_to_self_excluded(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/responsible-play/self-exclude', ['option_id' => 'exclude-6m']);
        $response->assertOk();
        $response->assertJson(['status' => 'self-excluded']);

        $snapshot = $this->withToken($token)->getJson('/v1/responsible-play')->json();
        $this->assertSame('self-excluded', $snapshot['status']);
    }

    public function test_an_invalid_duration_option_is_refused(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/responsible-play/cool-off', ['option_id' => 'not-a-real-option']);

        $response->assertStatus(422);
    }
}
