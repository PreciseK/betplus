<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\HeritageSecondChanceEntry;
use App\Models\Player;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class HeritageTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348032234567', int $fundedKobo = 1_000_000): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Adaeze Nwosu', 'registrationChannel' => 'web', 'kycTier' => 1,
            'ninHash' => hash('sha256', $msisdn),
        ]);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
        $tokens = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json();

        if ($fundedKobo > 0) {
            app(WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);
        }

        return [$player, $tokens['access_token']];
    }

    /** @param list<int> $winningPositions */
    private function fakeEngineResolve(string $outcomeTier, int $grossPrizeKobo, array $winningPositions, int $matchCount, ?int $secondChanceStakeKobo = null): void
    {
        Http::fake(['*/engine/v1/resolve' => Http::response([
            'ticket_id' => 'stub',
            'outcome_tier' => $outcomeTier,
            'gross_prize_kobo' => $grossPrizeKobo,
            'engine_state' => [
                'board' => [10, 20, 30, 40, 50, 60, 70, 80, 90],
                'winning_positions' => $winningPositions,
                'selected_positions' => [0, 1, 2, 3, 4],
                'match_count' => $matchCount,
                'tradition' => 'yoruba',
                'leader_type' => 'king',
                'second_chance_stake_kobo' => $secondChanceStakeKobo,
            ],
            'engine_version' => 'heritage-1.0.0',
            'digest' => hash('sha256', $outcomeTier),
        ])]);
    }

    public function test_the_game_descriptor_reflects_the_seeded_prize_table(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);

        $response = $this->withToken($token)->getJson('/v1/games/heritage');

        $response->assertOk();
        $response->assertJson([
            'game_name' => 'Heritage',
            'play_balance_kobo' => 500_000,
            'board_size' => 9,
            'pick_size' => 5,
            'prize_table_version' => 'HG-NG-2026.1',
        ]);
        $this->assertCount(3, $response->json('tiers'));
        $this->assertCount(7, $response->json('traditions'));
    }

    public function test_a_purchase_response_discloses_no_outcome_information(): void
    {
        [, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_LOSS', 0, [1, 2, 3, 4, 5], 1);

        $response = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-1',
        ]);

        $response->assertOk();
        $body = $response->json();
        foreach (['board', 'winning_positions', 'match_count', 'outcome_tier', 'won', 'gross_prize_kobo'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body, "Purchase response leaked '$forbidden' before reveal.");
        }
    }

    public function test_reveal_discloses_the_full_board_and_position_statuses(): void
    {
        [, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_JACKPOT', 250_000, [0, 1, 2, 3, 4], 5);

        $purchase = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-2',
        ]);
        $reference = $purchase->json('reference');

        $response = $this->withToken($token)->getJson("/v1/heritage/tickets/{$reference}/reveal");

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('TIER_JACKPOT', $body['outcome_tier']);
        $this->assertSame(5, $body['match_count']);
        $this->assertCount(9, $body['positions']);
        $this->assertTrue($body['positions'][0]['picked']);
        $this->assertTrue($body['positions'][0]['winning']);
        // REQ-HG-013 — every one of the 9 positions is marked, not just the picked ones.
        $this->assertFalse($body['positions'][8]['picked']);
    }

    public function test_a_cash_tier_credits_winnings_balance_net_of_withholding(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_JACKPOT', 250_000, [0, 1, 2, 3, 4], 5);

        $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-3',
        ])->assertOk();

        $wallet = app(WalletService::class)->walletFor($player->fresh());
        $this->assertGreaterThan(0, $wallet->winningsBalanceKobo);
        $this->assertLessThan(250_000, $wallet->winningsBalanceKobo); // net of withholding
    }

    public function test_a_second_chance_tier_queues_a_draw_entry_and_records_the_house_cost(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_SECOND_CHANCE', 0, [0, 1, 2, 5, 6], 3, secondChanceStakeKobo: 10_000);

        $response = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-4',
        ]);
        $response->assertOk();

        $ticket = Ticket::where('reference', $response->json('reference'))->firstOrFail();
        $entry = HeritageSecondChanceEntry::where('ticketId', $ticket->id)->first();

        $this->assertNotNull($entry);
        $this->assertSame('SECOND_CHANCE_PENDING', $entry->status);
        $this->assertSame(10_000, $entry->entryStakeKobo);
        // REQ-HG-030 — "the player's five selected numbers": a valid 5/90 line needs
        // exactly 5 numbers, so all 5 of the player's picks are entered, not just the
        // 3 that happened to be winning positions on this ticket.
        $this->assertSame([10, 20, 30, 40, 50], $entry->selectedNumbers);

        // A draw entry is a loss for the player's own balance (no cash prize) —
        // settled the same way TIER_LOSS is.
        $wallet = app(WalletService::class)->walletFor($player->fresh());
        $this->assertSame(0, $wallet->winningsBalanceKobo);
    }

    public function test_tradition_and_leader_are_snapshotted_onto_the_player_profile(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_LOSS', 0, [1, 2, 3, 4, 5], 1);

        $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-5',
            'tradition' => 'igbo',
            'leader_type' => 'queen',
        ])->assertOk();

        $player->refresh();
        $this->assertSame('igbo', $player->heritageTraditionCode);
        $this->assertSame('queen', $player->heritageLeaderType);
    }

    public function test_a_replay_with_the_same_idempotency_key_returns_the_same_ticket(): void
    {
        [, $token] = $this->signedInPlayer();
        $this->fakeEngineResolve('TIER_LOSS', 0, [1, 2, 3, 4, 5], 1);

        $first = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-6',
        ]);
        $second = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2, 3, 4],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-6',
        ]);

        $this->assertSame($first->json('reference'), $second->json('reference'));
    }

    public function test_purchase_rejects_fewer_than_5_selected_positions(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 1, 2],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-7',
        ]);

        $response->assertStatus(422);
    }

    public function test_purchase_rejects_duplicate_selected_positions(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/heritage/tickets', [
            'selected_positions' => [0, 0, 1, 2, 3],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'hg-test-8',
        ]);

        $response->assertStatus(422);
    }
}
