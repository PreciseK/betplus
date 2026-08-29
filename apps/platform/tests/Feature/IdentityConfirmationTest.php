<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityConfirmationTest extends TestCase
{
    use RefreshDatabase;

    // Throwaway test-only key (never used against real OPay) — OpayPayoutSigner needs
    // something valid to sign with; Http::fake() intercepts below it either way.
    // openssl_pkey_new() can't generate one at runtime on this host (no openssl.cnf),
    // so this is pre-generated via the openssl CLI instead.
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDU3Q79BRHGVwj1
    lbo9nrFPsvydFlVME8cgRCy0BG0Hpw4EPOEpWFlFItdvEoo/sEwiVm/epi4AEud/
    h5n2iTnM/Z46ZFqESgm4Q+g3UENkAWta2hUWNKHlTPukjWP6L0nkFGGZpnKrfkIQ
    BgMEpUSyQEyUb6Tk3nEPTS2zilrMFtU0d/lJVAzXGkFLtymNkIZwUS60QQkYbeNr
    FVJbwI3z0K0miUPdTm5nYu0NtJd60pE2VzoNUxvZ3X4c4RtFSCdyx0xyduuKDuQk
    R/qHEuO9j07FFqVyR1F4juZmwLxl6DjGkLI56jte/NOckQxief3Kf8NrTmC0fh4j
    /gIWwqI9AgMBAAECggEAHJBKW9kDjNguh1fvbSfflriHreOqkAIiaRnE3uYuJEX+
    O0LZGwV0OzME8i5sd0XmvX/YVKn7h76BqosNdbft1exdgGvpepF90uhn345JcMDB
    AWi8xiVLaTvmk6r2dMLGOVEj1KyxfAI+Bqzr2EJ+IKZAsHV3zM9tn/ZFEO/apcKW
    6fNiq51o18DKPRUYa5smPBOp5JLzrx/wFfr+cKXX/f381Y0vJTC5GfLWYnjsZO3V
    VCjDzhi0FhiiPGy0wwjK+tLHbt4eL6TPEUMcME7TKX595vnEU39ESnbqVg462xN7
    aWXhVLalKPGYDwEeAgaBXk9ZK4mmQql03T168/fSmQKBgQDyJISIoYUuVaRgfdNq
    LMi0TrONVBFjuwCpN2+Qm9jDKPjprZi7ThuunLkglxxidSNUw5g/IlB+9kkFT09r
    YPgc/uY5rV1ihC7HTf2UdcRgb9EJb9P3t5TX4avOQxtNl54J+anE4cgyA3KOVvun
    OzWb0wi/mHqfuwsF7f/IEY/MBQKBgQDhC5cdSN75uABW+s+jyl/yWXOYtWcV6LMX
    otZ7XW7OpZyf4IqR9yzrMIcpqC5/j/5koJ7bvsybzZ4Nzsk+xzkUeGmtwu/VbcxM
    FJaFyQa+RWfyxzpqzEFJg9BnfInrVEXf+WU2wby5HulK6LQGlA7NOkIz7HpvlM2P
    aQyUWZSK2QKBgDErcS49fknWYjal1lRtG6RhhtxgAdf6lTvHYgQ/YVjf7QumkKkY
    R07BzGXtyXnEx5Pi0/ueADKH2HQXksz/N+LLb/yuU5Q5uzYFhEStVV8v1YbRCn32
    7WaZEMYlolmzPAhShkLQhlKBmLWGvDtNLqmhxNkDIYNl++sMVTBPQJ/xAoGADrbQ
    SZTjJ1a1hvpdKytnPJRGr5xkwhT16Ly341cHkLFZXUa0KLkNkc8Zd0rMx4BltLSf
    zmRaQnGePO7hT559B+6bkkXlooHMUskh0luDeltVYZVPJ351YlYhATMuXVmkO/G1
    gXAHY982h7RRWQDDOv3tKDH1C2iiTBclQGnfAXkCgYBp6rRXMhdaA/EwiF0EHvfQ
    roww+6AM1m8ysaot4ujMDOUHBdP7mI2uAVpPx4FbZBHPaCzqRzaPdp0uP9+E5eBM
    bT2M4K3XNGl/027YXA1G616PBWVyA+Kat2uYt+9nzsqS9zLEg9KfUY/gknzN89YV
    /y+2JPqGSeGn0LPGFFk79g==
    -----END PRIVATE KEY-----
    PEM;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('opay.payout_private_key', self::TEST_PRIVATE_KEY_PEM);
        Config::set('opay.merchant_id', 'test-merchant');
    }

    private function verifiedSession(string $msisdn = '+2348031234567'): SignupSession
    {
        return SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
    }

    public function test_found_wallet_populates_name_for_confirmation(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response([
            'code' => '00000',
            'message' => 'Transaction success',
            'data' => ['phone' => '8031234567', 'firstName' => 'Ada', 'lastName' => 'Okafor'],
        ])]);
        $this->verifiedSession();

        $response = $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'confirm', 'registered_name' => 'Ada Okafor']);
        $this->assertSame('Ada Okafor', SignupSession::first()->registeredName);
    }

    public function test_no_wallet_tells_player_plainly_and_is_recorded(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response(['code' => '01005', 'message' => 'Incorrect Account Number'])]);
        $this->verifiedSession();

        $response = $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'no_wallet']);
        $this->assertDatabaseHas('nameLookupCache', ['msisdn' => '+2348031234567', 'lookupStatus' => 'not_found']);
    }

    public function test_unreachable_opay_is_recoverable_and_preserves_session(): void
    {
        Http::fake(['*/opay-wallet-validate' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);
        $session = $this->verifiedSession();

        $response = $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'error']);
        // Progress preserved: still otp-verified, unconsumed, ready to retry (UX-DR12).
        $this->assertNotNull($session->fresh()->otpVerifiedAt);
        $this->assertNull($session->fresh()->consumedAt);
    }

    public function test_confirm_identity_requires_a_verified_otp(): void
    {
        SignupSession::create([
            'msisdn' => '+2348031234567',
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'expiresAt' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'otp_not_verified']);
    }

    public function test_complete_creates_player_with_opay_name_not_player_input(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response([
            'code' => '00000',
            'data' => ['firstName' => 'Ada', 'lastName' => 'Okafor'],
        ])]);
        $this->verifiedSession();
        $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        $response = $this->postJson('/v1/auth/register/complete', ['msisdn' => '08031234567']);

        $response->assertOk()->assertJson(['status' => 'registered']);
        $this->assertDatabaseHas('player', [
            'msisdn' => '+2348031234567',
            'registeredName' => 'Ada Okafor',
            'kycTier' => 0,
        ]);
        $this->assertNotNull(SignupSession::first()->consumedAt);
    }

    public function test_second_lookup_reuses_cache_instead_of_billing_opay_again(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response([
            'code' => '00000',
            'data' => ['firstName' => 'Ada', 'lastName' => 'Okafor'],
        ])]);
        $this->verifiedSession();

        $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);
        $this->postJson('/v1/auth/register/confirm-identity', ['msisdn' => '08031234567']);

        Http::assertSentCount(1);
    }
}
