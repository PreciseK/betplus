<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class FundingTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    // Throwaway test-only key (never used against real OPay) — OpayPayoutSigner needs
    // something valid to sign with; Http::fake() intercepts below it either way.
    // Reused verbatim from IdentityConfirmationTest, which faces the same constraint.
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
        $this->refreshVaultConnection();
        Config::set('opay.payout_private_key', self::TEST_PRIVATE_KEY_PEM);
        Config::set('opay.merchant_id', 'test-merchant');
    }

    private function signedInPlayer(string $msisdn = '+2348031234567', string $registeredName = 'Ada Okafor'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn,
            'registeredName' => $registeredName,
            'registrationChannel' => 'web',
            'kycTier' => 1,
        ]);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');

        return [$player, $token];
    }

    private function fakeVerifiedWallet(string $firstName = 'Ada', string $lastName = 'Okafor', int $floatKobo = 100_000_000): void
    {
        Http::fake([
            '*/opay-wallet-validate' => Http::response(['code' => '00000', 'data' => ['firstName' => $firstName, 'lastName' => $lastName]]),
            '*/payout/balance' => Http::response(['code' => '00000', 'data' => ['balance' => ['total' => $floatKobo]]]),
        ]);
    }

    public function test_quote_is_always_fee_free(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits/quote', ['amount_kobo' => 250_000]);

        $response->assertOk()->assertJson([
            'amount_kobo' => 250_000,
            'fee_kobo' => 0,
            'fee_verified' => true,
            'reversible' => false,
        ]);
    }

    public function test_full_deposit_flow_credits_play_balance_only(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeVerifiedWallet();

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-deposit-001']);

        $response->assertOk()->assertJson(['status' => 'paid', 'credited_kobo' => 250_000]);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
        $this->assertSame(0, $wallet->winningsBalanceKobo);
    }

    public function test_deposit_is_rejected_when_opay_wallet_name_does_not_match_registered_name(): void
    {
        [$player, $token] = $this->signedInPlayer(registeredName: 'Ada Okafor');
        $this->fakeVerifiedWallet(firstName: 'Bola', lastName: 'Tinubu');

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-mismatch-001']);

        $response->assertStatus(422)->assertJson(['status' => 'wallet_unverified']);
        $this->assertNull(PlayerWallet::where('playerId', $player->id)->first());
    }

    public function test_deposit_is_rejected_when_no_opay_wallet_is_found(): void
    {
        [$player, $token] = $this->signedInPlayer();
        Http::fake(['*/opay-wallet-validate' => Http::response(['code' => '01005', 'message' => 'Incorrect Account Number'])]);

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-no-wallet-001']);

        $response->assertStatus(422)->assertJson(['status' => 'wallet_unverified']);
        $this->assertNull(PlayerWallet::where('playerId', $player->id)->first());
    }

    public function test_deposit_is_rejected_when_merchant_float_cannot_cover_it(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeVerifiedWallet(floatKobo: 100_000);

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-float-001']);

        $response->assertStatus(422)->assertJson(['status' => 'float_unavailable']);
        $this->assertNull(PlayerWallet::where('playerId', $player->id)->first());
    }

    public function test_replayed_deposit_with_same_reference_does_not_double_credit(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeVerifiedWallet();

        $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-replay-001']);
        $second = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-replay-001']);

        $second->assertOk()->assertJson(['status' => 'paid', 'credited_kobo' => 250_000]);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
    }

    public function test_reusing_another_players_reference_does_not_disclose_their_deposit(): void
    {
        [, $tokenA] = $this->signedInPlayer('+2348031234567', 'Ada Okafor');
        $this->fakeVerifiedWallet();
        $this->withToken($tokenA)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'shared-ref-001']);

        [$playerB, $tokenB] = $this->signedInPlayer('+2348039999999', 'Bola Tinubu');
        $this->fakeVerifiedWallet(firstName: 'Bola', lastName: 'Tinubu');
        $response = $this->withToken($tokenB)->postJson('/v1/wallet/deposits', ['quote_id' => '100000', 'reference' => 'shared-ref-001']);

        $response->assertStatus(422)->assertJson(['status' => 'reference_conflict']);
        $this->assertArrayNotHasKey('credited_kobo', $response->json());
        $this->assertNull(PlayerWallet::where('playerId', $playerB->id)->first());
    }

    public function test_deposit_reference_accepts_a_full_length_client_generated_uuid(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeVerifiedWallet();
        $uuidReference = '3fa85f64-5717-4562-b3fc-2c963f66afa6'; // 36 chars, e.g. crypto.randomUUID()

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => $uuidReference]);

        $response->assertOk()->assertJson(['status' => 'paid', 'reference' => $uuidReference]);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
    }

    public function test_wallet_shows_balances_and_transaction_history(): void
    {
        [, $token] = $this->signedInPlayer();
        $this->fakeVerifiedWallet();
        $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000', 'reference' => 'ref-history-001']);

        $wallet = $this->withToken($token)->getJson('/v1/wallet');
        $wallet->assertOk()->assertJson(['play_balance_kobo' => 250_000, 'currency' => 'NGN']);

        $history = $this->withToken($token)->getJson('/v1/wallet/transactions');
        $history->assertOk();
        $this->assertCount(1, $history->json('transactions'));
        $this->assertSame('paid', $history->json('transactions.0.status'));
    }
}
