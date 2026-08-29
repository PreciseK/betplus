<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ussd\UssdGatewaySigner;
use App\Models\Player;
use App\Models\UssdSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UssdGatewayTest extends TestCase
{
    use RefreshDatabase;

    // Same throwaway test-only PEM IdentityConfirmationTest uses — OpayPayoutSigner
    // needs something valid to sign with; Http::fake() intercepts below it either way.
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
        Config::set('ussd.gateway_shared_secret', 'test-shared-secret');
    }

    /** @param array<string, mixed> $body */
    private function postSigned(string $uri, array $body): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = app(UssdGatewaySigner::class)->sign($json);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Ussd-Signature' => $signature,
        ], $json);
    }

    public function test_a_request_with_no_signature_is_rejected(): void
    {
        $response = $this->postJson('/internal/ussd/identify', ['msisdn' => '+2348031234567']);

        $response->assertStatus(403);
    }

    public function test_a_request_with_a_wrong_signature_is_rejected(): void
    {
        $response = $this->call('POST', '/internal/ussd/identify', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-Ussd-Signature' => 'not-the-real-signature',
        ], json_encode(['msisdn' => '+2348031234567']));

        $response->assertStatus(403);
    }

    public function test_identify_for_a_new_msisdn_returns_the_opay_name_for_confirmation_with_no_otp(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response([
            'code' => '00000', 'data' => ['firstName' => 'Chidinma', 'lastName' => 'Eze'],
        ])]);

        $response = $this->postSigned('/internal/ussd/identify', ['msisdn' => '+2348031234567']);

        $response->assertOk()->assertJson(['status' => 'confirm_identity', 'registered_name' => 'Chidinma Eze']);
        $this->assertDatabaseMissing('player', ['msisdn' => '+2348031234567']);
    }

    public function test_register_complete_creates_a_player_with_ussd_as_the_registration_channel(): void
    {
        Http::fake(['*/opay-wallet-validate' => Http::response([
            'code' => '00000', 'data' => ['firstName' => 'Tunde', 'lastName' => 'Bakare'],
        ])]);
        $this->postSigned('/internal/ussd/identify', ['msisdn' => '+2348039876543']);

        $response = $this->postSigned('/internal/ussd/register/complete', ['msisdn' => '+2348039876543']);

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('registered', $body['status']);
        $this->assertNotEmpty($body['access_token']);
        $this->assertDatabaseHas('player', ['msisdn' => '+2348039876543', 'registrationChannel' => 'ussd']);
    }

    public function test_identify_for_an_existing_player_signs_in_directly_with_no_otp(): void
    {
        $player = Player::create([
            'msisdn' => '+2348022345678', 'registeredName' => 'Existing Player',
            'registrationChannel' => 'ussd', 'kycTier' => 1,
        ]);

        $response = $this->postSigned('/internal/ussd/identify', ['msisdn' => '+2348022345678']);

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('signed_in', $body['status']);
        $this->assertNotEmpty($body['access_token']);
        $this->assertSame('Existing Player', $body['registered_name']);

        // The issued token is a real, usable /v1 bearer token — same trust surface
        // web/app get from a normal sign-in.
        $me = $this->withToken($body['access_token'])->getJson('/v1/wallet');
        $me->assertOk();
    }

    public function test_session_sync_writes_an_audit_row_keyed_on_session_and_msisdn(): void
    {
        $this->postSigned('/internal/ussd/session', [
            'session_id' => 'sess-1', 'msisdn' => '+2348031234567',
            'screen' => 'main_menu', 'input_text' => '1', 'status' => 'active',
        ]);

        $this->assertDatabaseHas('ussdSession', [
            'sessionId' => 'sess-1', 'msisdn' => '+2348031234567', 'screen' => 'main_menu', 'status' => 'active',
        ]);
    }

    public function test_session_sync_is_idempotent_per_session_and_msisdn(): void
    {
        $this->postSigned('/internal/ussd/session', [
            'session_id' => 'sess-2', 'msisdn' => '+2348031234567', 'screen' => 'welcome', 'status' => 'active',
        ]);
        $this->postSigned('/internal/ussd/session', [
            'session_id' => 'sess-2', 'msisdn' => '+2348031234567', 'screen' => 'main_menu', 'status' => 'active',
        ]);

        $this->assertDatabaseCount('ussdSession', 1);
        $this->assertDatabaseHas('ussdSession', ['sessionId' => 'sess-2', 'screen' => 'main_menu']);
    }

    public function test_the_cleanup_command_expires_stale_sessions(): void
    {
        UssdSession::create([
            'sessionId' => 'stale-1', 'msisdn' => '+2348031234567',
            'screen' => 'main_menu', 'status' => 'active', 'lastTurnAt' => now()->subMinutes(30),
        ]);
        UssdSession::create([
            'sessionId' => 'fresh-1', 'msisdn' => '+2348031234568',
            'screen' => 'main_menu', 'status' => 'active', 'lastTurnAt' => now(),
        ]);

        Artisan::call('ussd:cleanup-sessions');

        $this->assertSame('expired', UssdSession::where('sessionId', 'stale-1')->first()->status);
        $this->assertSame('active', UssdSession::where('sessionId', 'fresh-1')->first()->status);
    }
}
