<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedSessionForExistingPlayer(string $msisdn = '+2348031234567'): void
    {
        Player::create(['msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web']);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
    }

    public function test_sign_in_issues_tokens_and_sets_httponly_secure_samesite_lax_cookies(): void
    {
        $this->verifiedSessionForExistingPlayer();

        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'signed_in']);
        $response->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $accessCookie = $response->headers->getCookies()[0];
        $this->assertSame('access_token', $accessCookie->getName());
        $this->assertTrue($accessCookie->isHttpOnly());
        $this->assertTrue($accessCookie->isSecure());
        $this->assertSame('lax', $accessCookie->getSameSite());
    }

    public function test_sign_in_requires_a_verified_otp(): void
    {
        Player::create(['msisdn' => '+2348031234567', 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web']);

        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'otp_not_verified']);
    }

    public function test_expires_in_matches_the_30_minute_access_token_ttl(): void
    {
        $this->verifiedSessionForExistingPlayer();

        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);

        $response->assertJson(['expires_in' => 1800]);
    }

    public function test_refresh_rotates_the_token_and_the_old_one_stops_working(): void
    {
        $this->verifiedSessionForExistingPlayer();
        $signIn = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567'])->json();

        $response = $this->postJson('/v1/auth/refresh', ['refresh_token' => $signIn['refresh_token']]);

        $response->assertOk()->assertJson(['status' => 'signed_in']);
        $this->assertNotSame($signIn['refresh_token'], $response->json('refresh_token'));

        // The rotated-away token is now dead, not just superseded.
        $replay = $this->postJson('/v1/auth/refresh', ['refresh_token' => $signIn['refresh_token']]);
        $replay->assertJson(['status' => 'reuse_detected']);
    }

    public function test_reused_refresh_token_revokes_the_whole_family(): void
    {
        $this->verifiedSessionForExistingPlayer();
        $signIn = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567'])->json();
        $afterRefresh = $this->postJson('/v1/auth/refresh', ['refresh_token' => $signIn['refresh_token']])->json();

        // Replaying the original (already-rotated) token revokes the family...
        $this->postJson('/v1/auth/refresh', ['refresh_token' => $signIn['refresh_token']]);

        // ...so even the legitimately-rotated successor token no longer works — it's a
        // known token, just a dead one, so this is reuse_detected too, not invalid_token.
        $response = $this->postJson('/v1/auth/refresh', ['refresh_token' => $afterRefresh['refresh_token']]);
        $response->assertJson(['status' => 'reuse_detected']);
        $this->assertSame(
            2, // original + rotated
            PlayerSession::whereNotNull('terminatedAt')->count(),
        );
    }

    public function test_unknown_refresh_token_is_rejected(): void
    {
        $response = $this->postJson('/v1/auth/refresh', ['refresh_token' => 'not-a-real-token']);

        $response->assertOk()->assertJson(['status' => 'invalid_token']);
    }

    public function test_sign_in_also_sets_a_non_httponly_signed_in_flag_cookie(): void
    {
        $this->verifiedSessionForExistingPlayer();

        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);

        $flag = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'betplus_signed_in');
        $this->assertNotNull($flag);
        $this->assertSame('1', $flag->getValue());
        $this->assertFalse($flag->isHttpOnly());
    }

    public function test_refresh_works_via_the_httponly_cookie_without_a_body_token(): void
    {
        $this->verifiedSessionForExistingPlayer();
        $signIn = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567'])->json();

        $response = $this->withCredentials()->withUnencryptedCookie('refresh_token', $signIn['refresh_token'])->postJson('/v1/auth/refresh', []);

        $response->assertOk()->assertJson(['status' => 'signed_in']);
    }

    public function test_sign_out_revokes_the_refresh_token_and_clears_cookies(): void
    {
        $this->verifiedSessionForExistingPlayer();
        $signIn = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567'])->json();

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie('access_token', $signIn['access_token'])
            ->withUnencryptedCookie('refresh_token', $signIn['refresh_token'])
            ->postJson('/v1/auth/sign-out');

        $response->assertOk()->assertJson(['status' => 'signed_out']);

        $accessCookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'access_token');
        $this->assertNotNull($accessCookie);
        $this->assertTrue($accessCookie->getExpiresTime() < time());

        // The now-revoked refresh token can no longer mint a new session. It reuses the
        // same terminatedAt-checked branch as rotation reuse detection (SessionService
        // has no separate "signed out on purpose" reason) — status is reuse_detected,
        // not invalid_token, since the token is known, just already dead.
        $replay = $this->postJson('/v1/auth/refresh', ['refresh_token' => $signIn['refresh_token']]);
        $replay->assertJson(['status' => 'reuse_detected']);
    }

    public function test_sign_out_is_safe_to_call_with_no_cookies_at_all(): void
    {
        $response = $this->postJson('/v1/auth/sign-out');

        $response->assertOk()->assertJson(['status' => 'signed_out']);
    }

    public function test_a_flood_of_sign_in_attempts_from_one_ip_is_throttled(): void
    {
        $this->verifiedSessionForExistingPlayer();

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);
        }

        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => '08031234567']);
        $response->assertStatus(429);
    }
}
