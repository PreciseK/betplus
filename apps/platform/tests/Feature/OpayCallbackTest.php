<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Models\Collection;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class OpayCallbackTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
        config(['opay.collection_secret_key' => 'test-collection-secret']);
        // See FundingTest — QUEUE_CONNECTION=sync makes an unfaked dispatch() run
        // inline with a real OPay call.
        Queue::fake();
    }

    private function pendingCollection(): array
    {
        $player = Player::create([
            'msisdn' => '+2348031234567', 'registeredName' => 'Ada Okafor',
            'registrationChannel' => 'web', 'kycTier' => 1,
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $player->msisdn])->json('access_token');
        $this->withToken($token)->postJson('/v1/identity/verify-nin', ['date_of_birth' => '1990-05-20', 'nin' => '12345678901']);
        $this->withToken($token)->postJson('/v1/identity/verify-bvn', ['bvn' => '10987654321']);

        Http::fake(['*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']])]);
        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);
        $collection = Collection::find($create->json('collection_id'));

        return [$player, $collection, $token];
    }

    private function signedCallback(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = (new OpayCollectionSigner('test-collection-secret'))->sign($body);

        return $this->call('POST', '/internal/opay/callback/payin', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer $signature",
        ], $body);
    }

    public function test_valid_signed_callback_credits_the_wallet(): void
    {
        [$player, $collection] = $this->pendingCollection();

        $response = $this->signedCallback(['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $response->assertOk()->assertJson(['status' => 'paid']);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
    }

    public function test_unsigned_callback_is_rejected_and_does_not_credit(): void
    {
        [$player, $collection] = $this->pendingCollection();

        $response = $this->postJson('/internal/opay/callback/payin', ['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $response->assertStatus(403);
        $this->assertNull(PlayerWallet::where('playerId', $player->id)->first());
        $this->assertDatabaseHas('opayApiCallLog', ['outcome' => 'rejected_signature_invalid']);
    }

    public function test_callback_with_wrong_signature_is_rejected(): void
    {
        [, $collection] = $this->pendingCollection();
        $body = json_encode(['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $response = $this->call('POST', '/internal/opay/callback/payin', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer not-the-real-signature',
        ], $body);

        $response->assertStatus(403);
    }

    public function test_callback_for_unknown_reference_is_reported_not_credited(): void
    {
        $response = $this->signedCallback(['reference' => 'does-not-exist', 'status' => 'SUCCESS']);

        $response->assertOk()->assertJson(['status' => 'unknown_reference']);
    }

    public function test_callback_after_otp_already_paid_does_not_double_credit(): void
    {
        [$player, $collection, $token] = $this->pendingCollection();
        Http::fake(['*/payment/input-otp' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']])]);
        $this->withToken($token)->postJson("/v1/wallet/deposits/{$collection->id}/otp", ['otp' => '123456']);

        $response = $this->signedCallback(['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $response->assertOk()->assertJson(['status' => 'paid']);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo); // not 500,000
    }

    public function test_replayed_callback_does_not_double_credit(): void
    {
        [$player, $collection] = $this->pendingCollection();

        $this->signedCallback(['reference' => $collection->reference, 'status' => 'SUCCESS']);
        $this->signedCallback(['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
    }

    public function test_ip_allowlist_rejects_unlisted_source(): void
    {
        config(['opay.callback_ip_allowlist' => '203.0.113.9']);
        [, $collection] = $this->pendingCollection();

        $response = $this->signedCallback(['reference' => $collection->reference, 'status' => 'SUCCESS']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('opayApiCallLog', ['outcome' => 'rejected_ip_not_allowlisted']);
    }
}
