<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\PoolEntry;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HeritagePoolPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);

        GameEconomicsConfig::create([
            'gameCode' => 'HERITAGE',
            'version' => 'pool-v1',
            'status' => 'published',
            'activeModel' => 'PARI_MUTUEL_POOL',
            'paramsJson' => [
                'rake_bps' => 1500, 'pool_window_minutes' => 60,
                'tier_allocation_bps' => ['5' => 5000, '4' => 3000, '3' => 1500, '2' => 500],
            ],
            'effectiveAt' => now()->subMinute(),
            'publishedAt' => now()->subMinute(),
        ]);
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348032234567'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Adaeze Nwosu', 'registrationChannel' => 'web',
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

        $response = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 10_000,
            'idempotency_key' => 'hg-pool-1',
        ]);

        $response->assertOk()->assertJson(['status' => 'pending_draw']);
        $this->assertNotNull($response->json('draw_at'));

        $ticket = Ticket::where('idempotencyKey', 'hg-pool-1')->first();
        $this->assertSame('PENDING_DRAW', $ticket->status);

        $entry = PoolEntry::where('ticketId', $ticket->id)->first();
        $this->assertSame(10_000, $entry->stakeKobo);
        $this->assertSame([0, 1, 2, 3, 4], $entry->predictionJson);
    }

    public function test_reveal_of_a_pending_ticket_reports_pending_draw_not_404(): void
    {
        [, $token] = $this->signedInPlayer();
        $purchase = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 10_000,
            'idempotency_key' => 'hg-pool-2',
        ]);

        $reveal = $this->withToken($token)->getJson('/v1/heritage/tickets/' . $purchase->json('reference') . '/reveal');

        $reveal->assertOk()->assertJson(['status' => 'pending_draw']);
    }
}
