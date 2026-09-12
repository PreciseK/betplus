<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\PrizeTable;
use App\Models\PrizeTableTier;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CagedTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348033234567', int $kycTier = 1, int $fundedKobo = 1_000_000): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web', 'kycTier' => $kycTier,
            'ninHash' => $kycTier >= 1 ? hash('sha256', $msisdn) : null,
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

    public function test_the_game_descriptor_reflects_the_seeded_prize_table_and_live_balance(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);

        $response = $this->withToken($token)->getJson('/v1/games/caged');

        $response->assertOk();
        $response->assertJson([
            'game_name' => 'Caged',
            'play_balance_kobo' => 500_000,
            'currency' => 'NGN',
            'prize_table_version' => 'CG-NG-2026.1',
        ]);
        // Mirrors BlackRedController::show() field-for-field (turnover, daily limit,
        // and tax/ruleset fields) — a subset assertJson() match wouldn't fail if these
        // ever went missing again, so assert the full structure explicitly.
        $response->assertJsonStructure([
            'game_name', 'description', 'play_balance_kobo', 'winnings_balance_kobo',
            'turnover_staked_kobo', 'turnover_required_kobo',
            'daily_limit_kobo', 'daily_limit_remaining_kobo',
            'min_stake_kobo', 'max_stake_kobo', 'currency', 'state_name',
            'tax_rate_basis_points', 'tax_basis_label', 'ruleset_version',
            'prize_table_version', 'engine_version', 'tiers',
        ]);
        $this->assertCount(5, $response->json('tiers'));
    }

    public function test_a_purchase_response_discloses_no_outcome_information(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2,
            'stake_kobo' => 100_000,
            'idempotency_key' => 'caged-test-purchase-1',
        ]);

        $response->assertOk();
        $body = $response->json();

        foreach (['escaped_birds', 'won', 'gross_prize_kobo', 'tax_withheld_kobo', 'net_credit_kobo', 'winnings_balance_after_kobo'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body, "Purchase response leaked '$forbidden' before reveal.");
        }
        $this->assertSame('purchased', $body['status']);
        $this->assertSame(2, $body['target_birds']);
        $this->assertSame(100_000, $body['stake_kobo']);
        $this->assertSame(900_000, $body['play_balance_after_kobo']);
    }

    public function test_reveal_discloses_the_full_settled_outcome(): void
    {
        [, $token] = $this->signedInPlayer();

        $purchase = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1,
            'stake_kobo' => 100_000,
            'idempotency_key' => 'caged-test-reveal-1',
        ])->json();

        $reveal = $this->withToken($token)->getJson("/v1/caged/tickets/{$purchase['reference']}/reveal");

        $reveal->assertOk();
        $reveal->assertJsonStructure([
            'reference', 'escaped_birds', 'won', 'gross_prize_kobo', 'tax_withheld_kobo',
            'net_credit_kobo', 'winnings_balance_after_kobo', 'tax_rate_basis_points',
            'tax_basis_label', 'ruleset_version', 'prize_table_version', 'engine_version', 'state_name',
        ]);
        $this->assertSame($purchase['reference'], $reveal->json('reference'));
    }

    public function test_a_win_credits_winnings_balance_net_of_withholding(): void
    {
        [, $token] = $this->signedInPlayer();

        // target=1 wins ~71.8% of the time — 20 tries is overwhelmingly enough.
        $won = null;
        for ($i = 0; $i < 20 && $won === null; $i++) {
            $purchase = $this->withToken($token)->postJson('/v1/caged/tickets', [
                'target_birds' => 1,
                'stake_kobo' => 10_000,
                'idempotency_key' => "caged-win-search-$i",
            ])->json();
            $reveal = $this->withToken($token)->getJson("/v1/caged/tickets/{$purchase['reference']}/reveal")->json();
            if ($reveal['won']) {
                $won = $reveal;
            }
        }

        $this->assertNotNull($won, 'No win in 20 target=1 tickets — engine bias is suspect.');
        $this->assertSame(12_500, $won['gross_prize_kobo']); // 10_000 * 1.25
        $this->assertSame(625, $won['tax_withheld_kobo']); // 5% of gross
        $this->assertSame(11_875, $won['net_credit_kobo']);
        $this->assertSame($won['winnings_balance_after_kobo'], $won['net_credit_kobo']);
    }

    public function test_a_repeated_idempotency_key_returns_the_same_ticket_without_double_charging(): void
    {
        [$player, $token] = $this->signedInPlayer();

        $first = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-replay-key',
        ])->json();
        $second = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-replay-key',
        ])->json();

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, Ticket::where('playerId', $player->id)->where('gameCode', 'CAGED')->count());
    }

    public function test_a_stake_below_the_kyc_gate_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(kycTier: 0);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-kyc-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
    }

    public function test_a_stake_exceeding_play_balance_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 5_000);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-balance-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
        $this->assertSame(5_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }

    public function test_a_stake_outside_the_game_registry_range_is_refused(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 5_000_000);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 5, 'idempotency_key' => 'caged-stake-range',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
    }

    public function test_a_target_outside_one_to_five_is_rejected_by_validation(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 6, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-target-range',
        ]);

        $response->assertStatus(422);
    }

    /**
     * The publication gate is supposed to prevent this — a malformed tier set
     * reaching CreateCagedTicket requires the seeded, already-published prize table
     * to be corrupted afterwards. We simulate that directly (bypassing the gate) to
     * confirm CagedEngine::validateTiers()'s InvalidArgumentException surfaces as a
     * clean 422 GAME_UNAVAILABLE rather than an uncaught 500.
     */
    public function test_a_malformed_published_prize_table_fails_purchase_cleanly_instead_of_500ing(): void
    {
        [, $token] = $this->signedInPlayer();

        $table = PrizeTable::where('gameCode', 'CAGED')->where('version', 'CG-NG-2026.1')->firstOrFail();
        // Remove a tier other than the one being purchased so the earlier
        // "no tier for this target" check still passes and the malformed set
        // reaches the engine's validateTiers().
        PrizeTableTier::where('prizeTableId', $table->id)->where('positions', 3)->delete();

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-malformed-tiers',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
    }

    public function test_every_ticket_leaves_the_ledger_in_balance(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 2_000_000);

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/v1/caged/tickets', [
                'target_birds' => 2, 'stake_kobo' => 50_000, 'idempotency_key' => "caged-balance-check-$i",
            ]);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();

        $this->assertSame($totals->debits, $totals->credits);
    }
}
