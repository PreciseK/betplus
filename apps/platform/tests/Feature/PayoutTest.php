<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payout\DispatchPayout;
use App\Domain\Payout\PayoutService;
use App\Domain\Payout\SettlePayoutStatus;
use App\Domain\Wallet\WalletService;
use App\Jobs\DispatchPrizePayoutJob;
use App\Models\FloatSnapshot;
use App\Models\Payout;
use App\Models\Player;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PayoutTest extends TestCase
{
    use RefreshDatabase;

    // Same throwaway test key as OpaySignerSeparationTest — DispatchPayout genuinely
    // signs with openssl, so a syntactically valid PEM is required even though no real
    // network call reaches OPay (Http::fake() intercepts it).
    private const TEST_RSA_KEY_PEM = <<<'PEM'
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
        config(['opay.payout_private_key' => self::TEST_RSA_KEY_PEM]);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348031234567', int $fundedKobo = 1_000_000): array
    {
        // Epic 5's exclusion-registry gate requires a verified NIN (REQ-RG-012).
        $player = Player::create(['msisdn' => $msisdn, 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web', 'kycTier' => 1, 'ninHash' => hash('sha256', $msisdn)]);
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

    private function winningTicket(Player $player): Ticket
    {
        $create = app(\App\Domain\Ticket\CreateTicket::class);
        for ($i = 0; $i < 40; $i++) {
            $ticket = $create->create($player, ['B'], 10_000, "payout-test-win-$i");
            if ($ticket->outcome->won) {
                return $ticket;
            }
        }
        $this->fail('No win in 40 attempts — engine bias is suspect.');
    }

    public function test_a_winning_ticket_queues_the_automatic_prize_payout_job(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);

        Queue::assertPushed(DispatchPrizePayoutJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_a_losing_ticket_never_queues_a_payout(): void
    {
        [$player, $token] = $this->signedInPlayer();

        // Not every ticket wins, but at least one loss is overwhelmingly likely.
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/v1/tickets', [
                'prediction' => ['B', 'B', 'B'],
                'stake_kobo' => 10_000,
                'idempotency_key' => "no-payout-search-$i",
            ]);
        }

        Queue::assertNotPushed(DispatchPrizePayoutJob::class, function ($job) {
            $ticket = Ticket::with('outcome')->find($job->ticketId);

            return $ticket?->outcome && !$ticket->outcome->won;
        });
    }

    public function test_dispatch_creates_a_payout_and_calls_opay_with_kobo_amount(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);

        Http::fake(['*/payout/createSingleOrder' => Http::response([
            'code' => '00000', 'message' => 'SUCCESSFUL',
            'data' => ['orderNo' => 'OPAY-TEST-1', 'reference' => 'x', 'orderStatus' => 'INITIAL'],
        ])]);

        $payout = app(DispatchPayout::class)->forWonTicket($ticket);

        $this->assertNotNull($payout);
        $this->assertSame($ticket->outcome->netCreditKobo, $payout->amountKobo);
        $this->assertSame('INITIAL', $payout->providerStatus);
        $this->assertSame('OPAY-TEST-1', $payout->opayOrderNo);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'createSingleOrder')
            && $request->data()['amount'] === $ticket->outcome->netCreditKobo // REQ-PO-012 — kobo, not Naira
            && $request->data()['payoutType'] === 'OpayWalletNg');
    }

    public function test_a_second_dispatch_for_the_same_ticket_returns_the_existing_payout_without_a_new_opay_call(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-1', 'orderStatus' => 'INITIAL']])]);

        $dispatch = app(DispatchPayout::class);
        $first = $dispatch->forWonTicket($ticket);
        $second = $dispatch->forWonTicket($ticket);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payout::where('ticketId', $ticket->id)->count());
        Http::assertSentCount(1);
    }

    public function test_an_unrecognised_opay_status_is_never_treated_as_failure(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-1', 'orderStatus' => 'INITIAL']])]);
        $payout = app(DispatchPayout::class)->forWonTicket($ticket);

        app(SettlePayoutStatus::class)->apply($payout, 'A_BRAND_NEW_CODE_OPAY_INVENTED');

        $payout->refresh();
        $this->assertSame('A_BRAND_NEW_CODE_OPAY_INVENTED', $payout->providerStatus);
        $this->assertTrue($payout->manualReviewRequired);
        $this->assertNull($payout->confirmedAt);
        // The player's winnings credit from settlement must be untouched.
        $this->assertSame($ticket->outcome->netCreditKobo, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_a_confirmed_success_moves_winnings_balance_to_the_float_account(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-1', 'orderStatus' => 'INITIAL']])]);
        $payout = app(DispatchPayout::class)->forWonTicket($ticket);

        app(SettlePayoutStatus::class)->apply($payout, 'SUCCESS');

        $payout->refresh();
        $this->assertSame('SUCCESS', $payout->providerStatus);
        $this->assertNotNull($payout->confirmedAt);
        $this->assertSame(0, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_a_confirmed_failure_leaves_the_prize_credited_and_queues_no_refund_since_it_never_left(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-1', 'orderStatus' => 'INITIAL']])]);
        $payout = app(DispatchPayout::class)->forWonTicket($ticket);
        $netCredit = $ticket->outcome->netCreditKobo;

        app(SettlePayoutStatus::class)->apply($payout, 'FAIL');

        $payout->refresh();
        $this->assertSame('FAIL', $payout->providerStatus);
        // REQ-PO-008/009 — the net prize remains credited throughout.
        $this->assertSame($netCredit, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_float_halt_suspends_automatic_disbursement_without_losing_the_prize(): void
    {
        [$player] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        FloatSnapshot::create(['opayBalanceKobo' => 1, 'alertState' => 'halt', 'polledAt' => now()]);
        Http::fake(); // any call here would be a bug

        $payout = app(DispatchPayout::class)->forWonTicket($ticket);

        $this->assertSame('FLOAT_HALTED', $payout->providerStatus);
        Http::assertNothingSent();
        // REQ-FLOAT-006 — winnings stay credited, nothing was ever debited.
        $this->assertSame($ticket->outcome->netCreditKobo, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_a_withdrawal_above_manual_review_threshold_is_held_and_never_calls_opay(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 0);
        // Fund Winnings Balance directly above the threshold via a winning run isn't
        // practical here — credit it through the settlement path used elsewhere.
        app(WalletService::class)->settleWin($player, 0, 200_000_000, 0, 200_000_000, 'test', 1);
        Http::fake();

        $quote = $this->withToken($token)->postJson('/v1/payouts/quote', ['source' => 'winnings', 'amount_kobo' => 200_000_000])->json();
        $this->assertTrue($quote['manual_review_required']);

        $payout = $this->withToken($token)->postJson('/v1/payouts', ['quote_id' => $quote['quote_id']])->json();

        $this->assertSame('MANUAL_REVIEW', $payout['provider_status']);
        Http::assertNothingSent();
        // Winnings Balance was still reserved out (money leaves the free balance the
        // moment a withdrawal is requested, whether or not it's under manual review).
        $this->assertSame(0, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_a_below_threshold_withdrawal_reserves_funds_and_dispatches_to_opay(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 0);
        app(WalletService::class)->settleWin($player, 0, 50_000, 0, 50_000, 'test', 1);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-WD-1', 'orderStatus' => 'INITIAL']])]);

        $quote = $this->withToken($token)->postJson('/v1/payouts/quote', ['source' => 'winnings', 'amount_kobo' => 50_000])->json();
        $this->assertFalse($quote['manual_review_required']);

        $payout = $this->withToken($token)->postJson('/v1/payouts', ['quote_id' => $quote['quote_id']])->json();

        $this->assertSame('INITIAL', $payout['provider_status']);
        $this->assertSame('withdrawal', $payout['kind']);
        $this->assertSame(0, app(WalletService::class)->walletFor($player)->winningsBalanceKobo);
    }

    public function test_a_withdrawal_exceeding_winnings_balance_is_refused(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 0);
        app(WalletService::class)->settleWin($player, 0, 10_000, 0, 10_000, 'test', 1);

        $response = $this->withToken($token)->postJson('/v1/payouts/quote', ['source' => 'winnings', 'amount_kobo' => 999_999]);

        $response->assertStatus(422);
        $this->assertSame(0, Payout::count());
    }

    public function test_every_payout_operation_leaves_the_ledger_in_balance(): void
    {
        [$player, $token] = $this->signedInPlayer();
        $ticket = $this->winningTicket($player);
        Http::fake(['*/payout/createSingleOrder' => Http::response(['code' => '00000', 'data' => ['orderNo' => 'OPAY-1', 'orderStatus' => 'INITIAL']])]);
        $payout = app(DispatchPayout::class)->forWonTicket($ticket);
        app(SettlePayoutStatus::class)->apply($payout, 'SUCCESS');

        $quote = $this->withToken($token)->postJson('/v1/payouts/quote', ['source' => 'winnings', 'amount_kobo' => 1_000])->json();
        if (($quote['manual_review_required'] ?? true) === false && isset($quote['quote_id'])) {
            $this->withToken($token)->postJson('/v1/payouts', ['quote_id' => $quote['quote_id']]);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();

        $this->assertSame($totals->debits, $totals->credits);
    }
}
