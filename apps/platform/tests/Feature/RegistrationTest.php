<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_normalises_msisdn_and_sends_otp(): void
    {
        $response = $this->postJson('/v1/auth/register', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'otp_sent']);
        $this->assertDatabaseHas('signupSession', ['msisdn' => '+2348031234567']);
    }

    public function test_existing_account_is_not_disclosed_before_otp_is_verified(): void
    {
        Player::create([
            'msisdn' => '+2348031234567',
            'registeredName' => 'Existing Player',
            'registrationChannel' => 'web',
        ]);

        $response = $this->postJson('/v1/auth/register', ['msisdn' => '08031234567']);

        // Response is identical in shape whether or not the account exists.
        $response->assertOk()->assertJson(['status' => 'otp_sent']);
        $response->assertJsonMissingPath('exists');
    }

    public function test_correct_otp_for_existing_account_routes_to_sign_in(): void
    {
        Player::create([
            'msisdn' => '+2348031234567',
            'registeredName' => 'Existing Player',
            'registrationChannel' => 'web',
        ]);
        $session = SignupSession::create([
            'msisdn' => '+2348031234567',
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'expiresAt' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/v1/auth/register/verify', [
            'msisdn' => '08031234567',
            'code' => '123456',
        ]);

        $response->assertOk()->assertJson(['status' => 'verified', 'next' => 'sign_in']);
        $this->assertNotNull($session->fresh()->otpVerifiedAt);
    }

    public function test_correct_otp_for_new_number_routes_to_identity_confirmation_and_creates_no_account(): void
    {
        SignupSession::create([
            'msisdn' => '+2348031234567',
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'expiresAt' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/v1/auth/register/verify', [
            'msisdn' => '08031234567',
            'code' => '123456',
        ]);

        $response->assertOk()->assertJson(['status' => 'verified', 'next' => 'identity_confirmation']);
        $this->assertSame(0, Player::count());
    }

    public function test_wrong_otp_is_rejected_without_revealing_why(): void
    {
        SignupSession::create([
            'msisdn' => '+2348031234567',
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'expiresAt' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/v1/auth/register/verify', [
            'msisdn' => '08031234567',
            'code' => '000000',
        ]);

        $response->assertOk()->assertJson(['status' => 'invalid_or_expired']);
    }

    public function test_sixth_verify_attempt_is_rate_limited(): void
    {
        SignupSession::create([
            'msisdn' => '+2348031234567',
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'expiresAt' => now()->addMinutes(5),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/register/verify', ['msisdn' => '08031234567', 'code' => '000000']);
        }
        // The 6th attempt is blocked by the rate limiter even with the correct code.
        $response = $this->postJson('/v1/auth/register/verify', ['msisdn' => '08031234567', 'code' => '123456']);

        $response->assertOk()->assertJson(['status' => 'invalid_or_expired', 'next' => 'resend']);
    }

    public function test_fourth_resend_in_an_hour_is_silently_capped(): void
    {
        // Travel past each per-attempt cooldown so the hourly cap is what's actually tested.
        // Cooldowns after calls 1/2/3 are 30s/60s/120s — space calls past each one.
        foreach ([0, 31, 92, 213] as $secondsFromStart) {
            $this->travelTo(now()->addSeconds($secondsFromStart));
            $response = $this->postJson('/v1/auth/register', ['msisdn' => '08031234567']);
        }

        // Same success shape either way — only 3 sessions were actually created.
        $response->assertOk()->assertJson(['status' => 'otp_sent']);
        $this->assertSame(3, SignupSession::where('msisdn', '+2348031234567')->count());
    }

    public function test_invalid_number_is_rejected(): void
    {
        $response = $this->postJson('/v1/auth/register', ['msisdn' => '12345']);

        $response->assertStatus(422);
    }
}
