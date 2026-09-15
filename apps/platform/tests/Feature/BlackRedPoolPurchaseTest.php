<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\PoolEntry;
use App\Models\SignupSession;
use App\Models\Ticket;
use App\Domain\Wallet\WalletService;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BlackRedPoolPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);

        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED',
            'version' => 'pool-v1',
            'status' => 'published',
            'activeModel' => 'PARI_MUTUEL_POOL',
            'paramsJson' => ['rake_bps' => 1500, 'pool_window_minutes' => 60],
            'effectiveAt' => now()->subMinute(),
            'publishedAt' => now()->subMinute(),
        ]);
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348031234567'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', $msisdn),
        ]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', $player->id);

        return [$player, $token];
    }

    public function test_purchasing_under_pari_mutuel_pool_returns_pending_draw_not_a_result(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B', 'R'],
            'stake_kobo' => 50_000,
            'idempotency_key' => 'pool-test-1',
        ]);

        $response->assertOk()->assertJson(['status' => 'pending_draw']);
        $this->assertNotNull($response->json('draw_at'));
        $this->assertArrayNotHasKey('won', $response->json());

        $ticket = Ticket::where('idempotencyKey', 'pool-test-1')->first();
        $this->assertSame('PENDING_DRAW', $ticket->status);

        $entry = PoolEntry::where('ticketId', $ticket->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(50_000, $entry->stakeKobo);
        $this->assertSame(['B', 'R'], $entry->predictionJson);
    }

    public function test_reveal_of_a_pending_ticket_reports_pending_draw_not_404(): void
    {
        [, $token] = $this->signedInPlayer();
        $purchase = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'],
            'stake_kobo' => 20_000,
            'idempotency_key' => 'pool-test-2',
        ]);

        $reveal = $this->withToken($token)->getJson('/v1/tickets/' . $purchase->json('reference') . '/reveal');

        $reveal->assertOk()->assertJson(['status' => 'pending_draw']);
    }

    public function test_two_tickets_of_different_lengths_join_separate_pools(): void
    {
        [, $token] = $this->signedInPlayer();

        $this->withToken($token)->postJson('/v1/tickets', ['prediction' => ['B'], 'stake_kobo' => 10_000, 'idempotency_key' => 'pool-len-1']);
        $this->withToken($token)->postJson('/v1/tickets', ['prediction' => ['B', 'R'], 'stake_kobo' => 10_000, 'idempotency_key' => 'pool-len-2']);

        $entries = PoolEntry::with('poolDraw')->get();
        $this->assertCount(2, $entries);
        $this->assertNotSame($entries[0]->poolDrawId, $entries[1]->poolDrawId);
        $this->assertSame(1, $entries->firstWhere('ticketId', Ticket::where('idempotencyKey', 'pool-len-1')->first()->id)->poolDraw->poolKey);
        $this->assertSame(2, $entries->firstWhere('ticketId', Ticket::where('idempotencyKey', 'pool-len-2')->first()->id)->poolDraw->poolKey);
    }
}
