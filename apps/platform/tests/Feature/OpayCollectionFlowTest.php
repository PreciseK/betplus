<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Models\Collection;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class OpayCollectionFlowTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    private const TEST_COLLECTION_SECRET = 'OPAYPRV_test_secret_key_12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
        Config::set('opay.collection_secret_key', self::TEST_COLLECTION_SECRET);
        Config::set('opay.collection_merchant_id', '256600000000000');
        Config::set('opay.collection_callback_url', 'http://localhost/internal/opay/callback/collection');
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

    public function test_initiate_deposit_and_complete_with_pin(): void
    {
        [$player, $token] = $this->signedInPlayer();

        Http::fake([
            '*/payment/create' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'orderNo' => 'OPAY_ORDER_99991',
                    'status' => 'PENDING',
                    'nextAction' => ['actionType' => 'INPUT_PIN'],
                    'amount' => ['total' => 50000, 'currency' => 'NGN'],
                ],
            ]),
            '*/payment/action/input-pin' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'orderNo' => 'OPAY_ORDER_99991',
                    'status' => 'SUCCESS',
                    'reference' => 'ref-pin-001',
                ],
            ]),
        ]);

        // Step 1: Init deposit
        $initResponse = $this->withToken($token)->postJson('/v1/wallet/deposits/init', [
            'amount_kobo' => 50000,
            'reference' => 'ref-pin-001',
        ]);

        $initResponse->assertOk()->assertJson([
            'status' => 'initiated',
            'order_no' => 'OPAY_ORDER_99991',
            'action_type' => 'INPUT_PIN',
            'reference' => 'ref-pin-001',
        ]);

        $collection = Collection::where('reference', 'ref-pin-001')->first();
        $this->assertNotNull($collection);
        $this->assertSame('pending_auth', $collection->status);
        $this->assertSame('OPAY_ORDER_99991', $collection->providerCollectionId);

        // Step 2: Submit PIN
        $pinResponse = $this->withToken($token)->postJson('/v1/wallet/deposits/pin', [
            'order_no' => 'OPAY_ORDER_99991',
            'pin' => '1234',
        ]);

        $pinResponse->assertOk()->assertJson([
            'status' => 'paid',
            'credited_kobo' => 50000,
            'reference' => 'ref-pin-001',
        ]);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(50000, $wallet->playBalanceKobo);
    }

    public function test_initiate_deposit_and_complete_with_otp(): void
    {
        [$player, $token] = $this->signedInPlayer();

        Http::fake([
            '*/payment/create' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'orderNo' => 'OPAY_ORDER_OTP_888',
                    'status' => 'PENDING',
                    'nextAction' => ['actionType' => 'INPUT_OTP'],
                    'amount' => ['total' => 100000, 'currency' => 'NGN'],
                ],
            ]),
            '*/payment/input-otp' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'orderNo' => 'OPAY_ORDER_OTP_888',
                    'status' => 'SUCCESS',
                    'reference' => 'ref-otp-001',
                ],
            ]),
        ]);

        $initResponse = $this->withToken($token)->postJson('/v1/wallet/deposits/init', [
            'amount_kobo' => 100000,
            'reference' => 'ref-otp-001',
        ]);

        $initResponse->assertOk()->assertJson([
            'status' => 'initiated',
            'action_type' => 'INPUT_OTP',
        ]);

        $otpResponse = $this->withToken($token)->postJson('/v1/wallet/deposits/otp', [
            'order_no' => 'OPAY_ORDER_OTP_888',
            'otp' => '543210',
        ]);

        $otpResponse->assertOk()->assertJson([
            'status' => 'paid',
            'credited_kobo' => 100000,
        ]);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(100000, $wallet->playBalanceKobo);
    }

    public function test_collection_webhook_callback_credits_ledger_idempotently(): void
    {
        [$player] = $this->signedInPlayer();

        $collection = Collection::create([
            'playerId' => $player->id,
            'reference' => 'ref-hook-777',
            'amountKobo' => 75000,
            'providerCollectionId' => 'OPAY_ORDER_HOOK_777',
            'authChallengeType' => 'INPUT_PIN',
            'status' => 'pending_auth',
        ]);

        $payload = [
            'amount' => '750',
            'channel' => 'USSD',
            'country' => 'NG',
            'currency' => 'NGN',
            'displayedFailure' => '',
            'fee' => '0',
            'feeCurrency' => 'NGN',
            'instrumentType' => 'BankAccount',
            'reference' => 'ref-hook-777',
            'refunded' => false,
            'status' => 'SUCCESS',
            'timestamp' => '2026-09-23T08:00:00Z',
            'token' => 'TOKEN_777',
            'transactionId' => 'TXN_777',
            'updated_at' => '2026-09-23T08:00:00Z',
        ];

        $signer = new OpayCollectionSigner(self::TEST_COLLECTION_SECRET);
        $canonical = sprintf(
            '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $payload['amount'],
            $payload['currency'],
            $payload['reference'],
            $payload['refunded'] ? 't' : 'f',
            $payload['status'],
            $payload['timestamp'],
            $payload['token'],
            $payload['transactionId']
        );
        $sha512 = hash_hmac('sha3-512', $canonical, self::TEST_COLLECTION_SECRET);

        $response = $this->postJson('/internal/opay/callback/collection', [
            'payload' => $payload,
            'sha512' => $sha512,
            'type' => 'transaction-status',
        ]);

        $response->assertOk()->assertJson(['code' => '00000', 'message' => 'SUCCESSFUL']);

        $collection->refresh();
        $this->assertSame('paid', $collection->status);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(75000, $wallet->playBalanceKobo);

        // Replay callback: must be idempotent and succeed without doubling credit
        $replayResponse = $this->postJson('/internal/opay/callback/collection', [
            'payload' => $payload,
            'sha512' => $sha512,
            'type' => 'transaction-status',
        ]);
        $replayResponse->assertOk();

        $wallet->refresh();
        $this->assertSame(75000, $wallet->playBalanceKobo);
    }
}
