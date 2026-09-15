<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class FundingTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    // Throwaway test-only key (never used against real OPay) — same one
    // IdentityConfirmationTest/PayoutTest use. OpayPayoutSigner (which
    // FundingService::directWithdrawFromOpay's wallet/balance verification calls go
    // through) needs something valid to sign with; Http::fake() intercepts below it.
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
        // QUEUE_CONNECTION=sync in phpunit.xml means an unfaked dispatch() runs inline,
        // synchronously, inside the HTTP request that created it — including
        // PollCollectionStatusJob's real (unmocked) OPay call. Fake it everywhere by
        // default; tests that specifically exercise the job unfake it themselves.
        Queue::fake();
    }

    private function signedInTier2Player(string $msisdn = '+2348031234567'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn,
            'registeredName' => 'Ada Okafor',
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

        // Reach Tier 2 (BVN) and back-fill the NIN record's dateOfBirth that
        // createCollection needs, exactly as the real onboarding sequence would.
        $this->withToken($token)->postJson('/v1/identity/verify-nin', [
            'date_of_birth' => '1990-05-20',
            'nin' => '12345678901',
        ]);
        $this->withToken($token)->postJson('/v1/identity/verify-bvn', ['bvn' => '10987654321']);

        return [$player, $token];
    }

    public function test_quote_is_always_fee_free(): void
    {
        [, $token] = $this->signedInTier2Player();

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits/quote', ['amount_kobo' => 250_000]);

        $response->assertOk()->assertJson([
            'amount_kobo' => 250_000,
            'fee_kobo' => 0,
            'fee_verified' => true,
            'reversible' => false,
        ]);
    }

    public function test_deposit_without_verified_bvn_is_blocked(): void
    {
        $player = Player::create(['msisdn' => '+2348039999999', 'registeredName' => 'No Bvn', 'registrationChannel' => 'web', 'kycTier' => 1]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $player->msisdn])->json('access_token');

        $response = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);

        $response->assertOk()->assertJson(['status' => 'bvn_required']);
    }

    public function test_full_deposit_flow_credits_play_balance_only(): void
    {
        [$player, $token] = $this->signedInTier2Player();
        Http::fake(['*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']])]);

        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);
        $create->assertOk()->assertJson(['status' => 'otp_required']);
        $collectionId = $create->json('collection_id');

        Http::fake(['*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']])]);
        $otp = $this->withToken($token)->postJson("/v1/wallet/deposits/$collectionId/otp", ['otp' => '123456']);

        $otp->assertOk()->assertJson(['status' => 'paid', 'amount_kobo' => 250_000]);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
        $this->assertSame(0, $wallet->winningsBalanceKobo);
    }

    public function test_concurrent_otp_submission_is_collapsed_to_one_opay_call(): void
    {
        // Simulates the exact race REQ-QA-008 guards against: a second request arrives
        // while the first is already mid-flight (status = finalizing), before either
        // has resolved 'paid'/'failed'. The second must not call OPay again.
        [$player, $token] = $this->signedInTier2Player();
        Http::fake(['*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']])]);
        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);
        $collectionId = $create->json('collection_id');

        Collection::where('id', $collectionId)->update(['status' => 'finalizing']);
        Http::fake(['*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']])]);

        $response = $this->withToken($token)->postJson("/v1/wallet/deposits/$collectionId/otp", ['otp' => '123456']);

        $response->assertOk()->assertJson(['status' => 'already_processing']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'input-otp'));
        $this->assertNull(PlayerWallet::where('playerId', $player->id)->first());
    }

    public function test_replayed_otp_submission_does_not_double_credit(): void
    {
        [$player, $token] = $this->signedInTier2Player();
        Http::fake([
            '*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']]),
            '*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']]),
        ]);

        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);
        $collectionId = $create->json('collection_id');

        $this->withToken($token)->postJson("/v1/wallet/deposits/$collectionId/otp", ['otp' => '123456']);
        $this->withToken($token)->postJson("/v1/wallet/deposits/$collectionId/otp", ['otp' => '123456']);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
        // create + otp attempt 1 only — attempt 2 short-circuits on the already-paid
        // check before ever calling OPay again, not just before crediting again.
        Http::assertSentCount(2);
    }

    public function test_wallet_shows_balances_and_transaction_history(): void
    {
        [$player, $token] = $this->signedInTier2Player();
        Http::fake([
            '*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']]),
            '*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']]),
        ]);
        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);
        $this->withToken($token)->postJson('/v1/wallet/deposits/' . $create->json('collection_id') . '/otp', ['otp' => '123456']);

        $wallet = $this->withToken($token)->getJson('/v1/wallet');
        $wallet->assertOk()->assertJson(['play_balance_kobo' => 250_000, 'currency' => 'NGN']);

        $history = $this->withToken($token)->getJson('/v1/wallet/transactions');
        $history->assertOk();
        $this->assertCount(1, $history->json('transactions'));
        $this->assertSame('paid', $history->json('transactions.0.status'));
    }

    public function test_failed_collection_does_not_credit_balance(): void
    {
        [$player, $token] = $this->signedInTier2Player();
        Http::fake(['*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']])]);
        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);

        Http::fake(['*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'FAILED']])]);
        $otp = $this->withToken($token)->postJson('/v1/wallet/deposits/' . $create->json('collection_id') . '/otp', ['otp' => '000000']);

        $otp->assertOk()->assertJson(['status' => 'failed']);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertNull($wallet);
    }

    /** @return array<string, \Illuminate\Http\Client\Response|\Closure> */
    private function fakeVerifiedOpayWallet(string $firstName = 'Ada', string $lastName = 'Okafor', int $floatKobo = 10_000_000): array
    {
        return [
            '*/opay-wallet-validate' => Http::response(['code' => '00000', 'data' => ['firstName' => $firstName, 'lastName' => $lastName]]),
            '*/payout/balance' => Http::response(['code' => '00000', 'data' => ['balance' => ['total' => $floatKobo, 'currency' => 'NGN']]]),
        ];
    }

    public function test_direct_withdraw_from_opay_credits_play_balance_immediately(): void
    {
        [$player, $token] = $this->signedInTier2Player();
        Http::fake($this->fakeVerifiedOpayWallet());

        $response = $this->withToken($token)->postJson('/v1/wallet/direct-withdraw-opay', [
            'amount_kobo' => 100_000,
            'reference' => 'opay-dir-ref-1',
        ]);

        $response->assertOk()->assertJson([
            'status' => 'paid',
            'credited_kobo' => 100_000,
            'play_balance_kobo' => 100_000,
            'reference' => 'opay-dir-ref-1',
        ]);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(100_000, $wallet->playBalanceKobo);
    }

    public function test_direct_withdraw_is_rejected_when_the_phone_has_no_opay_wallet(): void
    {
        [, $token] = $this->signedInTier2Player();
        Http::fake(['*/opay-wallet-validate' => Http::response(['code' => '00000', 'data' => []])]);

        $response = $this->withToken($token)->postJson('/v1/wallet/direct-withdraw-opay', [
            'amount_kobo' => 100_000,
            'reference' => 'opay-dir-ref-2',
        ]);

        $response->assertStatus(422)->assertJson(['status' => 'wallet_unverified']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payout/balance'));
        $this->assertNull(PlayerWallet::where('playerId', Player::first()->id)->first());
    }

    public function test_direct_withdraw_is_rejected_when_the_opay_wallet_name_does_not_match(): void
    {
        [, $token] = $this->signedInTier2Player();
        Http::fake($this->fakeVerifiedOpayWallet(firstName: 'Someone', lastName: 'Else'));

        $response = $this->withToken($token)->postJson('/v1/wallet/direct-withdraw-opay', [
            'amount_kobo' => 100_000,
            'reference' => 'opay-dir-ref-3',
        ]);

        $response->assertStatus(422)->assertJson(['status' => 'wallet_unverified']);
    }

    public function test_direct_withdraw_is_rejected_when_the_merchant_float_cannot_cover_it(): void
    {
        [, $token] = $this->signedInTier2Player();
        Http::fake($this->fakeVerifiedOpayWallet(floatKobo: 50_000));

        $response = $this->withToken($token)->postJson('/v1/wallet/direct-withdraw-opay', [
            'amount_kobo' => 100_000,
            'reference' => 'opay-dir-ref-4',
        ]);

        $response->assertStatus(422)->assertJson(['status' => 'float_unavailable']);
    }
}
