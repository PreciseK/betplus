<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BlackRedTicketTest extends TestCase
{
    use RefreshDatabase;

    // Same throwaway test-only key FundingTest/IdentityConfirmationTest use —
    // FundingService::directWithdrawFromOpay's wallet/balance verification calls
    // sign through OpayPayoutSigner even when Http::fake() intercepts the request.
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
        $this->seed(BlackRedGameSeeder::class);
        Config::set('opay.payout_private_key', self::TEST_PRIVATE_KEY_PEM);
        Config::set('opay.merchant_id', 'test-merchant');
        // A win now queues DispatchPrizePayoutJob (Epic 4, Story 4.1). With
        // QUEUE_CONNECTION=sync it would otherwise run inline and attempt a real OPay
        // call with no configured payout credentials — same class of bug as Story 2.4's
        // FundingTest/OpayCallbackTest fix.
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348031234567', int $kycTier = 1, int $fundedKobo = 1_000_000): array
    {
        // Epic 5's exclusion-registry gate requires a verified NIN (REQ-RG-012) — a
        // real Tier-1 upgrade always sets this; tests set it directly since they skip
        // that flow.
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web', 'kycTier' => $kycTier,
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

        $response = $this->withToken($token)->getJson('/v1/games/blackred');

        $response->assertOk();
        $response->assertJson([
            'game_name' => 'BlackRed',
            'play_balance_kobo' => 500_000,
            'currency' => 'NGN',
            'prize_table_version' => 'BR-NG-2026.1',
        ]);
        $this->assertCount(5, $response->json('tiers'));
    }

    public function test_a_purchase_response_discloses_no_outcome_information(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B', 'R'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'test-purchase-1',
        ]);

        $response->assertOk();
        $body = $response->json();

        // REQ-TKT-005 — no unrevealed numbers, no winning set, no tier outcome.
        foreach (['result', 'won', 'gross_prize_kobo', 'tax_withheld_kobo', 'net_credit_kobo', 'winnings_balance_after_kobo'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body, "Purchase response leaked '$forbidden' before reveal.");
        }
        $this->assertSame('purchased', $body['status']);
        $this->assertSame(100_000, $body['stake_kobo']);
        $this->assertSame(900_000, $body['play_balance_after_kobo']); // default 1_000_000 funding - 100_000 stake
    }

    public function test_reveal_discloses_the_full_settled_outcome(): void
    {
        [, $token] = $this->signedInPlayer();

        $purchase = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'test-reveal-1',
        ])->json();

        $reveal = $this->withToken($token)->getJson("/v1/tickets/{$purchase['reference']}/reveal");

        $reveal->assertOk();
        $reveal->assertJsonStructure([
            'reference', 'result', 'won', 'gross_prize_kobo', 'tax_withheld_kobo',
            'net_credit_kobo', 'winnings_balance_after_kobo', 'tax_rate_basis_points',
            'tax_basis_label', 'ruleset_version', 'prize_table_version', 'engine_version', 'state_name',
        ]);
        $this->assertSame($purchase['reference'], $reveal->json('reference'));
    }

    public function test_a_win_credits_winnings_balance_net_of_withholding(): void
    {
        [$player, $token] = $this->signedInPlayer();

        // Try enough single-position tickets that at least one wins (p ~ 1 - 0.5^30).
        $won = null;
        for ($i = 0; $i < 30 && $won === null; $i++) {
            $purchase = $this->withToken($token)->postJson('/v1/tickets', [
                'prediction' => ['B'],
                'stake_kobo' => 10_000,
                'idempotency_key' => "win-search-$i",
            ])->json();
            $reveal = $this->withToken($token)->getJson("/v1/tickets/{$purchase['reference']}/reveal")->json();
            if ($reveal['won']) {
                $won = $reveal;
            }
        }

        $this->assertNotNull($won, 'No win in 30 single-position tickets — engine bias is suspect.');
        $this->assertSame(17_000, $won['gross_prize_kobo']); // 10_000 * 1.70 (recalibrated 2026-09-14 for the 8800bp ceiling)
        $this->assertSame(850, $won['tax_withheld_kobo']); // 5% of gross
        $this->assertSame(16_150, $won['net_credit_kobo']);
        $this->assertSame($won['winnings_balance_after_kobo'], $won['net_credit_kobo']);
    }

    public function test_a_repeated_idempotency_key_returns_the_same_ticket_without_double_charging(): void
    {
        [$player, $token] = $this->signedInPlayer();

        $first = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B', 'R'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'replay-key',
        ])->json();
        $second = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B', 'R'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'replay-key',
        ])->json();

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, Ticket::where('playerId', $player->id)->count());
    }

    public function test_a_stake_below_the_kyc_gate_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(kycTier: 0);

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'kyc-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
    }

    public function test_a_stake_exceeding_play_balance_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 5_000);

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'],
            'stake_kobo' => 100_000,
            'idempotency_key' => 'balance-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_PLAY_BALANCE', $response->json('code'));
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
        $this->assertSame(5_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }

    public function test_a_stake_outside_the_game_registry_range_is_refused(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 5_000_000);

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'],
            'stake_kobo' => 5, // below minStakeKobo
            'idempotency_key' => 'stake-range',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
    }

    public function test_every_ticket_leaves_the_ledger_in_balance(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 2_000_000);

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/v1/tickets', [
                'prediction' => ['B', 'R'],
                'stake_kobo' => 50_000,
                'idempotency_key' => "balance-check-$i",
            ]);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();

        $this->assertSame($totals->debits, $totals->credits);
    }

    public function test_ussd_ticket_purchase_auto_funds_via_opay_when_play_balance_is_zero(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 0);

        // FundingService::directWithdrawFromOpay verifies the wallet (§2.6) and the
        // merchant float (§2.4) before crediting — see FundingTest for the dedicated
        // coverage of those gates.
        Http::fake([
            '*/opay-wallet-validate' => Http::response(['code' => '00000', 'data' => ['firstName' => 'Ada', 'lastName' => 'Okafor']]),
            '*/payout/balance' => Http::response(['code' => '00000', 'data' => ['balance' => ['total' => 10_000_000, 'currency' => 'NGN']]]),
        ]);

        $response = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B', 'R'],
            'stake_kobo' => 50_000,
            'idempotency_key' => 'ussd-br-sess-auto-fund',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('reference'));

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();
        $this->assertSame($totals->debits, $totals->credits);
    }
}
