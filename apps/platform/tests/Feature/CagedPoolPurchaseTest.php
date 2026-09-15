<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\PoolEntry;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CagedPoolPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);

        GameEconomicsConfig::create([
            'gameCode' => 'CAGED',
            'version' => 'pool-v1',
            'status' => 'published',
            'activeModel' => 'PARI_MUTUEL_POOL',
            'paramsJson' => ['rake_bps' => 1500, 'pool_window_minutes' => 60],
            'effectiveAt' => now()->subMinute(),
            'publishedAt' => now()->subMinute(),
        ]);
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348030001111'): array
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

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2,
            'stake_kobo' => 10_000,
            'idempotency_key' => 'cg-pool-1',
        ]);

        $response->assertOk()->assertJson(['status' => 'pending_draw']);
        $this->assertNotNull($response->json('draw_at'));

        $ticket = Ticket::where('idempotencyKey', 'cg-pool-1')->first();
        $this->assertSame('PENDING_DRAW', $ticket->status);

        $entry = PoolEntry::where('ticketId', $ticket->id)->first();
        $this->assertSame(10_000, $entry->stakeKobo);
        $this->assertSame([2], $entry->predictionJson);
    }

    public function test_reveal_of_a_pending_ticket_reports_pending_draw_not_404(): void
    {
        [, $token] = $this->signedInPlayer();
        $purchase = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1,
            'stake_kobo' => 10_000,
            'idempotency_key' => 'cg-pool-2',
        ]);

        $reveal = $this->withToken($token)->getJson('/v1/caged/tickets/' . $purchase->json('reference') . '/reveal');

        $reveal->assertOk()->assertJson(['status' => 'pending_draw']);
    }

    public function test_two_different_targets_join_the_same_single_pool(): void
    {
        [, $token] = $this->signedInPlayer();

        $this->withToken($token)->postJson('/v1/caged/tickets', ['target_birds' => 1, 'stake_kobo' => 10_000, 'idempotency_key' => 'cg-pool-3']);
        $this->withToken($token)->postJson('/v1/caged/tickets', ['target_birds' => 5, 'stake_kobo' => 10_000, 'idempotency_key' => 'cg-pool-4']);

        $entries = PoolEntry::all();
        $this->assertCount(2, $entries);
        $this->assertSame($entries[0]->poolDrawId, $entries[1]->poolDrawId);
    }
}
