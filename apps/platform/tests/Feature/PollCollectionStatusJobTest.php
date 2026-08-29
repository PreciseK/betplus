<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\FundingService;
use App\Jobs\PollCollectionStatusJob;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesVaultConnection;
use Tests\TestCase;

class PollCollectionStatusJobTest extends TestCase
{
    use RefreshDatabase;
    use RefreshesVaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshVaultConnection();
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

        Queue::fake(); // don't let createCollection's own dispatch interfere with these tests
        Http::fake(['*/payment/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'op-123']])]);
        $create = $this->withToken($token)->postJson('/v1/wallet/deposits', ['quote_id' => '250000']);

        return [$player, Collection::find($create->json('collection_id'))];
    }

    public function test_creating_a_collection_schedules_a_poll_90_seconds_out(): void
    {
        Queue::fake();
        [, $collection] = $this->pendingCollectionWithoutFakeQueue();

        Queue::assertPushed(PollCollectionStatusJob::class, fn ($job) => $job->collectionId === $collection->id);
    }

    private function pendingCollectionWithoutFakeQueue(): array
    {
        $player = Player::create([
            'msisdn' => '+2348039999998', 'registeredName' => 'Ada Okafor',
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

        return [$player, Collection::find($create->json('collection_id'))];
    }

    public function test_poll_resolves_a_paid_collection_and_credits_the_wallet(): void
    {
        [$player, $collection] = $this->pendingCollection();
        Http::fake(['*/payment/status' => Http::response(['code' => '00000', 'data' => ['status' => 'SUCCESS']])]);

        app(PollCollectionStatusJob::class, ['collectionId' => $collection->id, 'attempt' => 0])
            ->handle(app(\App\Domain\Payments\Providers\Opay\OpayGateway::class), app(FundingService::class));

        $this->assertSame('paid', $collection->fresh()->status);
        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
    }

    public function test_poll_reschedules_itself_with_backoff_when_still_unresolved(): void
    {
        [, $collection] = $this->pendingCollection();
        Http::fake(['*/payment/status' => Http::response(['code' => '00000', 'data' => ['status' => 'PENDING']])]);
        Queue::fake();

        app(PollCollectionStatusJob::class, ['collectionId' => $collection->id, 'attempt' => 2])
            ->handle(app(\App\Domain\Payments\Providers\Opay\OpayGateway::class), app(FundingService::class));

        Queue::assertPushed(PollCollectionStatusJob::class, function ($job) use ($collection) {
            return $job->collectionId === $collection->id && $job->attempt === 3;
        });
        $this->assertSame('processing', $collection->fresh()->status);
    }

    public function test_already_resolved_collection_is_a_no_op(): void
    {
        [, $collection] = $this->pendingCollection();
        $collection->forceFill(['status' => 'paid'])->save();
        Http::fake(); // any call here would be a bug

        app(PollCollectionStatusJob::class, ['collectionId' => $collection->id, 'attempt' => 0])
            ->handle(app(\App\Domain\Payments\Providers\Opay\OpayGateway::class), app(FundingService::class));

        Http::assertNothingSent();
    }

    public function test_unresolved_after_24_hours_is_marked_unknown_and_escalated(): void
    {
        [, $collection] = $this->pendingCollection();
        $collection->forceFill(['createdAt' => now()->subHours(25)])->save();
        Http::fake(); // must not even ask OPay again once the window has closed

        app(PollCollectionStatusJob::class, ['collectionId' => $collection->id, 'attempt' => 10])
            ->handle(app(\App\Domain\Payments\Providers\Opay\OpayGateway::class), app(FundingService::class));

        $this->assertSame('unknown', $collection->fresh()->status);
        $this->assertDatabaseHas('auditLog', ['action' => 'collection.unknown.escalated', 'targetId' => $collection->id]);
        Http::assertNothingSent();
    }
}
