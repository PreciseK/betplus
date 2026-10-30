<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Doors\DoorsService;
use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Domain\Wallet\WalletService;
use App\Models\DoorsRound;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\GameRegistry;
use App\Models\Player;
use App\Models\SignupSession;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Database\Seeders\BlackRedGameSeeder;
use Database\Seeders\DoorsGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DoorsRoundTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed([BlackRedGameSeeder::class, DoorsGameSeeder::class]);
        config(['doors.enabled' => true]);
        GameRegistry::where('gameCode', 'DOORS')->update(['status' => 'ACTIVE']);
        GameEconomicsConfig::where('gameCode', 'DOORS')->update(['status' => 'published', 'publishedAt' => now()]);
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
    }

    private function player(string $phone = '+2348031234567', int $kyc = 1): array
    {
        $player = Player::create(['msisdn' => $phone, 'registeredName' => 'Doors Tester', 'registrationChannel' => 'web', 'kycTier' => $kyc, 'ninHash' => hash('sha256', $phone), 'residencyStatus' => 'resident']);
        SignupSession::create(['msisdn' => $phone, 'otpHash' => hash('sha256', '123456'), 'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5)]);
        $token = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $phone])->json('access_token');
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', $player->id);
        return [$player, $token];
    }

    private function start(string $token, string $key = 'start-1', int $seconds = 10): array
    {
        return $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => $seconds, 'idempotency_key' => $key])->assertOk()->json();
    }

    private function pick(string $token, string $reference, int $index, string $key): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson("/v1/doors/rounds/{$reference}/open", ['door_index' => $index, 'idempotency_key' => $key]);
    }

    private function fixture(string $reference, int $multiplier): DoorsRound
    {
        $round = DoorsRound::where('ticketId', Ticket::where('reference', $reference)->value('id'))->firstOrFail();
        $board = array_fill(0, 20, 0); $board[19] = $multiplier;
        $round->update(['boardEncrypted' => $board]);
        return $round;
    }

    public function test_start_reserves_the_stake_but_never_discloses_hidden_rewards(): void
    {
        [$player, $token] = $this->player();
        $body = $this->start($token);
        $this->assertSame('in_play', $body['status']);
        $this->assertSame([], $body['opened']);
        $this->assertSame(980_000, $body['wallet']['play_balance_kobo']);
        foreach (['board', 'boardEncrypted', 'seed', 'configJson'] as $key) { $this->assertArrayNotHasKey($key, $body); }
        $this->assertNull($body['seed_hex']);
        $this->assertStringNotContainsString('[0,', (string) DB::table('doorsRound')->value('boardEncrypted'));
        $this->assertSame(100_000, DoorsRound::first()->exposureKobo);
        $this->assertSame(0, TicketOutcome::count());
    }

    public function test_each_reward_ends_immediately_and_settles_exactly_once(): void
    {
        [$player, $token] = $this->player();
        foreach ([3, 5, 2] as $multiplier) {
            $round = $this->start($token, "start-{$multiplier}");
            $this->fixture($round['reference'], $multiplier);
            $win = $this->pick($token, $round['reference'], 19, "pick-{$multiplier}")->assertOk();
            $win->assertJson(['status' => 'settled', 'gross_prize_kobo' => 20_000 * $multiplier, 'net_prize_kobo' => 19_000 * $multiplier]);
            $this->assertCount(1, $win->json('opened'));
            $before = app(WalletService::class)->walletFor($player)->refresh()->winningsBalanceKobo;
            $this->pick($token, $round['reference'], 18, "late-{$multiplier}")->assertOk()->assertJsonCount(1, 'opened');
            $this->pick($token, $round['reference'], 19, "pick-{$multiplier}")->assertOk();
            $this->assertSame($before, app(WalletService::class)->walletFor($player)->refresh()->winningsBalanceKobo);
        }
        $this->assertSame(3, TicketOutcome::count());
        $this->assertSame(0, (int) DoorsRound::sum('exposureKobo'));
        $debits = (int) DB::table('ledgerEntry')->where('direction', 'debit')->sum('amountKobo');
        $credits = (int) DB::table('ledgerEntry')->where('direction', 'credit')->sum('amountKobo');
        $this->assertSame($debits, $credits);
    }

    public function test_duplicate_empty_picks_do_not_consume_attempts_and_five_misses_end_the_round(): void
    {
        [, $token] = $this->player(); $round = $this->start($token); $this->fixture($round['reference'], 3);
        $this->pick($token, $round['reference'], 0, 'pick-0')->assertOk();
        $this->pick($token, $round['reference'], 0, 'duplicate-new-key')->assertOk()->assertJsonCount(1, 'opened');
        for ($i = 1; $i < 5; $i++) { $response = $this->pick($token, $round['reference'], $i, "pick-{$i}")->assertOk(); }
        $response->assertJson(['status' => 'settled', 'gross_prize_kobo' => 0]);
        $this->pick($token, $round['reference'], 19, 'too-late')->assertOk()->assertJsonCount(5, 'opened')->assertJson(['gross_prize_kobo' => 0]);
    }

    public function test_timeout_and_abandoned_round_sweep_release_exposure_without_a_reward(): void
    {
        [, $token] = $this->player(); $round = $this->start($token); $this->fixture($round['reference'], 5);
        $this->travel(11)->seconds();
        $this->pick($token, $round['reference'], 19, 'expired-pick')->assertOk()->assertJson(['status' => 'settled', 'gross_prize_kobo' => 0, 'opened' => []]);
        $this->start($token, 'abandoned');
        $this->travel(11)->seconds();
        $this->artisan('doors:expire-rounds')->assertExitCode(0);
        $this->assertSame(0, DoorsRound::where('status', 'IN_PLAY')->count());
    }

    public function test_one_round_failing_to_expire_does_not_strand_the_others(): void
    {
        [, $first] = $this->player(); [, $second] = $this->player('+2348031234568'); [$stranger] = $this->player('+2348031234569');
        $this->start($first, 'broken'); $this->start($second, 'healthy');
        // A round whose owner no longer matches its ticket cannot be read, so it cannot settle.
        DoorsRound::orderBy('id')->firstOrFail()->update(['playerId' => $stranger->id]);
        $this->travel(11)->seconds();
        $this->artisan('doors:expire-rounds')->assertExitCode(1);
        $this->assertSame(['IN_PLAY', 'SETTLED'], DoorsRound::orderBy('id')->pluck('status')->all());
    }

    public function test_retries_resume_without_double_debit_and_other_players_cannot_read_or_pick(): void
    {
        [$player, $token] = $this->player(); $first = $this->start($token); $second = $this->start($token);
        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, Ticket::where('gameCode', 'DOORS')->count());
        [, $other] = $this->player('+2348031234568');
        $this->withToken($other)->getJson('/v1/doors/rounds/' . $first['reference'])->assertNotFound();
        $this->pick($other, $first['reference'], 19, 'other-pick')->assertNotFound();
        $this->withToken($token)->getJson('/v1/games/doors')->assertOk()->assertJsonPath('active_round.reference', $first['reference']);
        $this->assertSame(980_000, app(WalletService::class)->walletFor($player)->refresh()->playBalanceKobo);
    }

    public function test_conflicting_idempotency_payload_and_second_active_round_are_rejected(): void
    {
        [, $token] = $this->player(); $this->start($token);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 30_000, 'duration_seconds' => 10, 'idempotency_key' => 'start-1'])->assertStatus(422);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'second'])->assertStatus(422)->assertJson(['code' => 'ROUND_IN_PROGRESS']);
    }

    public function test_missing_or_stale_funding_and_reserved_exposure_block_new_bets(): void
    {
        [, $token] = $this->player();
        FloatSnapshot::query()->delete();
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'missing-float'])->assertStatus(422);
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()->subMinutes(3)]);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'stale-float'])->assertStatus(422)->assertJson(['code' => 'FLOAT_UNAVAILABLE']);
        FloatSnapshot::create(['opayBalanceKobo' => 2_040_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'no-headroom'])->assertStatus(422)->assertJson(['code' => 'BANKROLL_EXPOSURE_CAP']);
        $this->assertSame(0, DoorsRound::count());
    }

    public function test_off_spec_configuration_cannot_start_a_round(): void
    {
        [, $token] = $this->player();
        $config = GameEconomicsConfig::where('gameCode', 'DOORS')->firstOrFail();
        $params = $config->paramsJson;
        $params['doors']['durations_seconds'] = [5];
        $config->update(['paramsJson' => $params]);
        $this->assertNotEmpty(app(GameEconomicsModelGate::class)->validate($config));
        // All weight on the 5x chest: 5 x 5/20 = 125% RTP, above the 88% ceiling.
        $params['doors']['durations_seconds'] = [10];
        $params['doors']['treasures'] = [['code' => 'GOLD', 'multiplier' => 3, 'weight_bps' => 1], ['code' => 'CHEST', 'multiplier' => 5, 'weight_bps' => 9998], ['code' => 'CARD', 'multiplier' => 2, 'weight_bps' => 1]];
        $config->update(['paramsJson' => $params]);
        $this->assertContains('Doors RTP exceeds the platform ceiling.', app(GameEconomicsModelGate::class)->validate($config));
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'bad-odds'])->assertStatus(422)->assertJson(['code' => 'GAME_UNAVAILABLE']);
    }

    public function test_deployment_and_kyc_gates_prevent_charging_the_wallet(): void
    {
        [, $token] = $this->player(kyc: 0);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'kyc'])->assertStatus(422)->assertJson(['code' => 'KYC_REQUIRED']);
        config(['doors.enabled' => false]);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'disabled'])->assertStatus(422);
        $this->assertSame(0, DoorsRound::count());
    }

    public function test_another_players_open_round_reserves_payout_capacity(): void
    {
        [, $first] = $this->player();
        [, $second] = $this->player('+2348031234568');
        FloatSnapshot::query()->update(['opayBalanceKobo' => 3_150_000]);
        $this->start($first);
        $this->withToken($second)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'competing-round'])->assertStatus(422)->assertJson(['code' => 'BANKROLL_EXPOSURE_CAP']);
        $this->assertSame(1, DoorsRound::count());
    }

    public function test_config_and_tax_changes_do_not_reprice_an_accepted_round(): void
    {
        [, $token] = $this->player(); $started = $this->start($token); $this->fixture($started['reference'], 3);
        config(['tax.withholding.resident_rate_basis_points' => 1500]);
        GameEconomicsConfig::where('gameCode', 'DOORS')->update(['status' => 'draft']);
        config(['doors.enabled' => false]);
        $this->pick($token, $started['reference'], 19, 'pinned-win')->assertOk()->assertJson(['gross_prize_kobo' => 60_000, 'tax_withheld_kobo' => 3000, 'net_prize_kobo' => 57_000]);
    }

    public function test_excluded_players_cannot_purchase_and_a_blank_certification_reference_fails(): void
    {
        [$player, $token] = $this->player();
        \App\Models\RegistryExclusion::updateOrCreate(['ninHash' => $player->ninHash], ['registryName' => 'test', 'excluded' => true, 'checkedAt' => now()]);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 10, 'idempotency_key' => 'excluded'])->assertStatus(422)->assertJson(['code' => 'EXCLUDED']);
        $config = GameEconomicsConfig::where('gameCode', 'DOORS')->firstOrFail();
        $params = $config->paramsJson;
        $params['doors']['certification_ref'] = ' ';
        $config->update(['paramsJson' => $params]);
        $this->assertContains('Doors requires a certification reference.', app(GameEconomicsModelGate::class)->validate($config));
        $this->assertSame(0, DoorsRound::count());
    }

    public function test_operator_replay_waits_until_settlement_and_detects_board_drift(): void
    {
        [, $token] = $this->player();
        $started = $this->start($token);
        $audit = app(\App\Http\Controllers\BackOffice\TicketAuditController::class);
        $this->assertSame(409, $audit->replay($started['reference'])->status());
        $round = DoorsRound::firstOrFail();
        $index = array_key_first(array_filter($round->boardEncrypted));
        $this->pick($token, $started['reference'], $index, 'replay-win')->assertOk();
        $this->assertTrue($audit->replay($started['reference'])->getData(true)['matches']);
        $drifted = $round->boardEncrypted;
        $drifted[$index] = 0;
        $round->update(['boardEncrypted' => $drifted]);
        $this->assertFalse($audit->replay($started['reference'])->getData(true)['matches']);
    }

    public function test_only_ten_second_rounds_and_stakes_from_200_naira_are_accepted(): void
    {
        [, $token] = $this->player();
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 20_000, 'duration_seconds' => 5, 'idempotency_key' => 'five'])->assertStatus(422)->assertJson(['code' => 'INVALID_DURATION']);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 19_999, 'duration_seconds' => 10, 'idempotency_key' => 'low'])->assertStatus(422)->assertJson(['code' => 'INVALID_STAKE']);
        $this->withToken($token)->postJson('/v1/doors/rounds', ['stake_kobo' => 35_000, 'duration_seconds' => 10, 'idempotency_key' => 'typed'])->assertOk()->assertJson(['stake_kobo' => 35_000, 'max_attempts' => 5, 'duration_seconds' => 10]);
        $this->assertSame(1, DoorsRound::count());
    }

    public function test_seed_is_hidden_in_play_and_revealed_after_settlement_to_verify_the_commitment(): void
    {
        [, $token] = $this->player();
        $started = $this->start($token);
        $this->assertNull($started['seed_hex']);
        $index = array_key_first(array_filter(DoorsRound::firstOrFail()->boardEncrypted));
        $settled = $this->pick($token, $started['reference'], $index, 'reveal')->assertOk()->json();
        $this->assertSame('settled', $settled['status']);
        $rules = DoorsRound::firstOrFail()->configJson['rules'];
        $replayed = (new \App\Domain\Games\Doors\DoorsEngine())->board($settled['seed_hex'], $settled['reference'], $rules);
        $this->assertSame($settled['commitment'], $replayed['commitment']);
    }

    public function test_remaining_time_is_never_zero_while_a_round_is_in_play(): void
    {
        [, $token] = $this->player();
        $this->freezeTime();
        $started = $this->start($token);
        $this->travel(9999)->milliseconds();
        $this->withToken($token)->getJson('/v1/doors/rounds/' . $started['reference'])->assertOk()->assertJson(['status' => 'in_play'])
            ->assertJsonPath('remaining_ms', fn (int $ms) => $ms >= 1);
    }
}
