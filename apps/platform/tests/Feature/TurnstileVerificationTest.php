<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_turnstile_pass_through_when_disabled(): void
    {
        Config::set('services.cloudflare.turnstile_enabled', false);
        Config::set('services.cloudflare.turnstile_secret_key', '');

        $verifier = TurnstileVerifier::make();
        $this->assertTrue($verifier->verify(null));
        $this->assertTrue($verifier->verify(''));
        $this->assertTrue($verifier->verify('any_token'));
    }

    public function test_turnstile_rejects_empty_token_when_enabled(): void
    {
        Config::set('services.cloudflare.turnstile_enabled', true);
        Config::set('services.cloudflare.turnstile_secret_key', '0x4AAAAAAtest_secret_key');

        $verifier = TurnstileVerifier::make();
        $this->assertFalse($verifier->verify(null));
        $this->assertFalse($verifier->verify(''));
        $this->assertFalse($verifier->verify('   '));
    }

    public function test_turnstile_verifies_valid_token_against_api(): void
    {
        Config::set('services.cloudflare.turnstile_enabled', true);
        Config::set('services.cloudflare.turnstile_secret_key', '0x4AAAAAAtest_secret_key');

        Http::fake([
            'challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'challenge_ts' => '2026-10-01T08:00:00.000Z',
                'hostname' => 'betplus.com.ng',
            ], 200),
        ]);

        $verifier = TurnstileVerifier::make();
        $this->assertTrue($verifier->verify('valid_token_123', '102.89.45.67'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === '0x4AAAAAAtest_secret_key'
                && $request['response'] === 'valid_token_123'
                && $request['remoteip'] === '102.89.45.67';
        });
    }

    public function test_turnstile_middleware_blocks_unverified_request(): void
    {
        Config::set('services.cloudflare.turnstile_enabled', true);
        Config::set('services.cloudflare.turnstile_secret_key', '0x4AAAAAAtest_secret_key');

        Http::fake([
            'challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $response = $this->postJson('/v1/auth/sign-in/complete', [
            'msisdn' => '+2348012345678',
            'cf-turnstile-response' => 'bad_token',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'turnstile_verification_failed',
        ]);
    }
}
