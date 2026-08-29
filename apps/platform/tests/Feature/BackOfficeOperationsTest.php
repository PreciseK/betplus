<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Domain\Fairness\SeedIssuer;
use App\Domain\Ticket\CreateTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\LedgerDiscrepancy;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BackOfficeOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
        Queue::fake();
    }

    private function player(): Player
    {
        return Player::create([
            'msisdn' => '+2348022' . random_int(100000, 999999),
            'registeredName' => 'BO Test Player', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', (string) random_int(1, 999999999)),
        ]);
    }

    private function institutionToken(string $role): string
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Operator', 'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => $role, 'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
        ]);
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'x'])->json();
        $mfa = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => app(TotpService::class)->currentCode($secret)])->json();

        return $mfa['access_token'];
    }

    // ── Story 6.2: audit log immutability ─────────────────────────────

    public function test_the_audit_log_rejects_an_update(): void
    {
        $log = AuditLog::create(['actorType' => 'system', 'action' => 'test.action']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AuditLog::where('id', $log->id)->update(['action' => 'tampered']);
    }

    // ── Story 6.4: Player 360 ──────────────────────────────────────────

    public function test_player_360_returns_profile_balances_and_masks_identifiers(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 200_000, 'collection', 1);
        $token = $this->institutionToken('support_agent');

        $response = $this->withToken($token)->getJson("/backoffice/v1/players/{$player->id}");

        $response->assertOk();
        $response->assertJsonPath('profile.id', $player->id);
        $response->assertJsonPath('balances.play_balance_kobo', 200_000);
        $response->assertJsonPath('profile.has_verified_nin', true);
        $body = $response->json();
        $this->assertArrayNotHasKey('nin', $body['profile']);
        $this->assertArrayNotHasKey('bvn', $body['profile']);
    }

    // ── Story 6.5: ticket replay ────────────────────────────────────────

    public function test_ticket_replay_matches_the_stored_outcome(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        $ticket = app(CreateTicket::class)->create($player, ['B'], 100_000, 'replay-test-1');
        $token = $this->institutionToken('compliance');

        $response = $this->withToken($token)->getJson("/backoffice/v1/tickets/{$ticket->reference}/replay");

        $response->assertOk();
        $response->assertJson(['matches' => true]);
        $this->assertSame($ticket->outcome->digest, $response->json('replayed.digest'));
    }

    // ── Story 6.6: reconciliation exception queue ─────────────────────

    public function test_reconciliation_exceptions_are_listed_and_resolvable(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        PlayerWallet::where('playerId', $player->id)->update(['playBalanceKobo' => 999]);
        app(\App\Domain\Wallet\ReconciliationService::class)->reconcileWalletBalances();
        $token = $this->institutionToken('finance');

        $list = $this->withToken($token)->getJson('/backoffice/v1/reconciliation/exceptions');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('exceptions')));

        $id = $list->json('exceptions.0.id');
        $resolve = $this->withToken($token)->postJson("/backoffice/v1/reconciliation/exceptions/{$id}/resolve");
        $resolve->assertOk();
        $this->assertSame('resolved', $resolve->json('status'));
    }

    public function test_reconciliation_is_refused_to_a_non_finance_role(): void
    {
        $token = $this->institutionToken('support_agent');

        $response = $this->withToken($token)->getJson('/backoffice/v1/reconciliation/exceptions');

        $response->assertStatus(403);
    }

    // ── Story 6.7: licence expiry stops play live ──────────────────────

    public function test_an_expired_state_licence_refuses_ticket_creation(): void
    {
        StateLicence::where('stateCode', 'LAG')->update(['expiresAt' => now()->subDay()]);
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);

        try {
            app(CreateTicket::class)->create($player, ['B'], 10_000, 'licence-expiry-test');
            $this->fail('Expected TicketEligibilityException');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('STATE_NOT_LICENSED', $e->errorCode);
        }
    }

    public function test_the_jurisdiction_console_flags_a_licence_expiring_soon(): void
    {
        StateLicence::where('stateCode', 'LAG')->update(['expiresAt' => now()->addDays(10)]);
        $token = $this->institutionToken('compliance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/jurisdictions');

        $response->assertOk();
        $lagos = collect($response->json('states'))->firstWhere('state_code', 'LAG');
        $this->assertTrue($lagos['expiry_alert']);
    }

    // ── Story 6.8: game registry / prize table draft ───────────────────

    public function test_a_game_ops_role_can_suspend_a_game_without_a_deploy(): void
    {
        $token = $this->institutionToken('game_ops');

        $response = $this->withToken($token)->patchJson('/backoffice/v1/games/BLACKRED', ['status' => 'SUSPENDED']);

        $response->assertOk();
        $this->assertSame('SUSPENDED', $response->json('status'));
    }

    public function test_a_non_game_ops_role_cannot_suspend_a_game(): void
    {
        $token = $this->institutionToken('support_agent');

        $response = $this->withToken($token)->patchJson('/backoffice/v1/games/BLACKRED', ['status' => 'SUSPENDED']);

        $response->assertStatus(403);
    }

    public function test_creating_a_prize_table_draft_reports_gate_errors_without_publishing(): void
    {
        $token = $this->institutionToken('game_ops');

        $response = $this->withToken($token)->postJson('/backoffice/v1/prize-tables', [
            'game_code' => 'BLACKRED', 'version' => 'DRAFT-TEST-1', 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 185, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ]);

        $response->assertOk();
        $this->assertSame('draft', $response->json('status'));
        // No actuarial cert supplied — the gate should flag it, but the table exists as a draft.
        $this->assertNotEmpty($response->json('gate_errors'));
    }

    public function test_prize_table_presets_are_ranked_fair_good_best_by_house_edge(): void
    {
        $token = $this->institutionToken('game_ops');

        $response = $this->withToken($token)->getJson('/backoffice/v1/prize-tables/presets?game_code=BLACKRED');

        $response->assertOk();
        $presets = collect($response->json('presets'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['fair', 'good', 'best'], $presets->keys()->all());

        $rtpAt1Card = fn (string $key) => collect($presets[$key]['tiers'])->firstWhere('positions', 1)['gross_rtp_basis_points'];
        // "Fair" is zero-margin (100% RTP); "best" has the highest house revenue ratio,
        // i.e. the lowest RTP of the three — that ordering is the whole point of the feature.
        $this->assertSame(10_000, $rtpAt1Card('fair'));
        $this->assertGreaterThan($rtpAt1Card('good'), $rtpAt1Card('fair'));
        $this->assertGreaterThan(0, $rtpAt1Card('best'));
        $this->assertLessThan($rtpAt1Card('good'), $rtpAt1Card('best'));
    }

    public function test_the_fair_preset_always_fails_the_publication_gate(): void
    {
        $token = $this->institutionToken('game_ops');
        $presets = collect($this->withToken($token)->getJson('/backoffice/v1/prize-tables/presets?game_code=BLACKRED')->json('presets'))->keyBy('key');
        $fairTiers = collect($presets['fair']['tiers'])->map(fn ($t) => [
            'positions' => $t['positions'], 'multiplier_hundredths' => $t['multiplier_hundredths'],
            'probability_numerator' => $t['probability_numerator'], 'probability_denominator' => $t['probability_denominator'],
        ])->all();

        $response = $this->withToken($token)->postJson('/backoffice/v1/prize-tables', [
            'game_code' => 'BLACKRED', 'version' => 'DRAFT-FAIR-1', 'effective_at' => now()->toDateString(),
            'actuarial_cert_ref' => 'ACT-TEST', 'tiers' => $fairTiers,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('gate_errors'));
        $this->assertStringContainsString('exceeds the 9500bp ceiling', $response->json('gate_errors.0'));
    }

    public function test_updating_a_draft_prize_table_replaces_its_tiers(): void
    {
        $token = $this->institutionToken('game_ops');
        $draft = $this->withToken($token)->postJson('/backoffice/v1/prize-tables', [
            'game_code' => 'BLACKRED', 'version' => 'DRAFT-EDIT-1', 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 185, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ])->json();

        $response = $this->withToken($token)->patchJson("/backoffice/v1/prize-tables/{$draft['id']}", [
            'version' => 'DRAFT-EDIT-1', 'effective_at' => now()->toDateString(), 'actuarial_cert_ref' => 'ACT-TEST',
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 140, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ]);

        $response->assertOk();
        $this->assertSame(140, $response->json('tiers.0.multiplier_hundredths'));
        $this->assertCount(1, $response->json('tiers'));
    }

    public function test_deleting_a_draft_prize_table_removes_it(): void
    {
        $token = $this->institutionToken('game_ops');
        $draft = $this->withToken($token)->postJson('/backoffice/v1/prize-tables', [
            'game_code' => 'BLACKRED', 'version' => 'DRAFT-DELETE-1', 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 185, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ])->json();

        $response = $this->withToken($token)->deleteJson("/backoffice/v1/prize-tables/{$draft['id']}");

        $response->assertOk();
        $this->assertDatabaseMissing('prizeTable', ['id' => $draft['id']]);
    }

    public function test_a_published_prize_table_can_neither_be_edited_nor_deleted(): void
    {
        $token = $this->institutionToken('game_ops');
        $published = PrizeTable::where('version', 'BR-NG-2026.1')->where('status', 'published')->firstOrFail();

        $update = $this->withToken($token)->patchJson("/backoffice/v1/prize-tables/{$published->id}", [
            'version' => $published->version, 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 100, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ]);
        $update->assertStatus(409);

        $delete = $this->withToken($token)->deleteJson("/backoffice/v1/prize-tables/{$published->id}");
        $delete->assertStatus(409);
        $this->assertDatabaseHas('prizeTable', ['id' => $published->id]);
    }

    // ── REQ-BO-002: every operator action actually reaches the audit log ──────

    public function test_suspending_a_game_writes_a_real_audit_log_entry(): void
    {
        $token = $this->institutionToken('game_ops');

        $this->withToken($token)->patchJson('/backoffice/v1/games/BLACKRED', ['status' => 'SUSPENDED'])->assertOk();

        $this->assertDatabaseHas('auditLog', [
            'actorType' => 'institutionUser',
            'action' => 'game_registry_updated',
            'targetTable' => 'gameRegistry',
        ]);
    }

    public function test_prize_table_draft_create_update_and_delete_each_write_a_real_audit_log_entry(): void
    {
        $token = $this->institutionToken('game_ops');
        $draft = $this->withToken($token)->postJson('/backoffice/v1/prize-tables', [
            'game_code' => 'BLACKRED', 'version' => 'DRAFT-AUDIT-1', 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 185, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ])->json();
        $this->assertDatabaseHas('auditLog', ['action' => 'prize_table_draft_created', 'targetTable' => 'prizeTable', 'targetId' => $draft['id']]);

        $this->withToken($token)->patchJson("/backoffice/v1/prize-tables/{$draft['id']}", [
            'version' => 'DRAFT-AUDIT-1', 'effective_at' => now()->toDateString(),
            'tiers' => [['positions' => 1, 'multiplier_hundredths' => 140, 'probability_numerator' => 1, 'probability_denominator' => 2]],
        ]);
        $this->assertDatabaseHas('auditLog', ['action' => 'prize_table_draft_updated', 'targetTable' => 'prizeTable', 'targetId' => $draft['id']]);

        $this->withToken($token)->deleteJson("/backoffice/v1/prize-tables/{$draft['id']}");
        $this->assertDatabaseHas('auditLog', ['action' => 'prize_table_draft_deleted', 'targetTable' => 'prizeTable', 'targetId' => $draft['id']]);
    }

    public function test_upserting_a_jurisdiction_licence_writes_a_real_audit_log_entry(): void
    {
        $token = $this->institutionToken('compliance');

        $this->withToken($token)->postJson('/backoffice/v1/jurisdictions', [
            'state_code' => 'LAG', 'licence_number' => 'LAG-2027-01',
            'issued_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString(),
            'ruleset_version' => '2027.1', 'remittance_status' => 'current',
        ])->assertOk();

        $this->assertDatabaseHas('auditLog', [
            'actorType' => 'institutionUser',
            'action' => 'jurisdiction_licence_upserted',
            'targetTable' => 'stateLicence',
        ]);
    }

    public function test_resolving_a_reconciliation_exception_writes_a_real_audit_log_entry(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        PlayerWallet::where('playerId', $player->id)->update(['playBalanceKobo' => 999]);
        app(\App\Domain\Wallet\ReconciliationService::class)->reconcileWalletBalances();
        $id = LedgerDiscrepancy::first()->id;
        $token = $this->institutionToken('finance');

        $this->withToken($token)->postJson("/backoffice/v1/reconciliation/exceptions/{$id}/resolve")->assertOk();

        $this->assertDatabaseHas('auditLog', [
            'actorType' => 'institutionUser',
            'action' => 'reconciliation_exception_resolved',
            'targetTable' => 'ledgerDiscrepancy',
            'targetId' => $id,
        ]);
    }

    public function test_approving_a_change_writes_a_real_audit_log_entry_at_the_decision_level(): void
    {
        $maker = $this->institutionToken('game_ops');
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        $proposeResponse = $this->withToken($maker)->postJson('/backoffice/v1/changes', [
            'change_type' => 'manual_credit_debit',
            'payload' => ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            'justification' => 'Compensating a support ticket.',
        ]);
        $checker = $this->institutionToken('finance');

        $this->withToken($checker)->postJson("/backoffice/v1/changes/{$proposeResponse->json('id')}/approve")->assertOk();

        $this->assertDatabaseHas('auditLog', [
            'actorType' => 'institutionUser',
            'action' => 'change_approved',
            'targetTable' => 'reviewableChange',
            'targetId' => $proposeResponse->json('id'),
        ]);
    }

    public function test_rejecting_a_change_writes_a_real_audit_log_entry_at_the_decision_level(): void
    {
        $maker = $this->institutionToken('game_ops');
        $player = $this->player();
        $proposeResponse = $this->withToken($maker)->postJson('/backoffice/v1/changes', [
            'change_type' => 'manual_credit_debit',
            'payload' => ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            'justification' => 'Compensating a support ticket.',
        ]);
        $checker = $this->institutionToken('finance');

        $this->withToken($checker)->postJson("/backoffice/v1/changes/{$proposeResponse->json('id')}/reject", [
            'reason' => 'Insufficient evidence.',
        ])->assertOk();

        $this->assertDatabaseHas('auditLog', [
            'actorType' => 'institutionUser',
            'action' => 'change_rejected',
            'targetTable' => 'reviewableChange',
            'targetId' => $proposeResponse->json('id'),
        ]);
    }

    public function test_the_audit_log_endpoint_lists_real_events_and_filters_by_action(): void
    {
        $token = $this->institutionToken('game_ops');
        $this->withToken($token)->patchJson('/backoffice/v1/games/BLACKRED', ['status' => 'SUSPENDED'])->assertOk();
        $this->withToken($token)->patchJson('/backoffice/v1/games/BLACKRED', ['status' => 'active'])->assertOk();
        $compliance = $this->institutionToken('compliance');

        $response = $this->withToken($compliance)->getJson('/backoffice/v1/audit-log?action=game_registry_updated');

        $response->assertOk();
        $events = $response->json('events');
        $this->assertGreaterThanOrEqual(2, count($events));
        foreach ($events as $event) {
            $this->assertSame('game_registry_updated', $event['action']);
        }
    }

    public function test_the_audit_log_endpoint_is_refused_to_a_non_compliance_role(): void
    {
        $token = $this->institutionToken('support_agent');

        $response = $this->withToken($token)->getJson('/backoffice/v1/audit-log');

        $response->assertStatus(403);
    }

    // ── Daily summary: real money and player figures, not fixture math ────────

    public function test_daily_summary_reports_real_stakes_wins_and_active_players_for_today(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        $ticket = app(CreateTicket::class)->create($player, ['B'], 100_000, 'daily-summary-test-1');
        $token = $this->institutionToken('finance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/daily-summary?date=' . now()->toDateString());

        $response->assertOk();
        $this->assertSame(100_000, $response->json('gross_stakes_kobo'));
        $this->assertSame($ticket->outcome->grossPrizeKobo, $response->json('gross_wins_kobo'));
        $this->assertSame(1, $response->json('active_players'));
        $this->assertSame(1, $response->json('new_players'));
    }

    public function test_daily_summary_scopes_stakes_to_the_requested_game_but_not_deposits(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        app(CreateTicket::class)->create($player, ['B'], 100_000, 'daily-summary-test-2');
        $token = $this->institutionToken('finance');

        $heritageScoped = $this->withToken($token)->getJson('/backoffice/v1/daily-summary?date=' . now()->toDateString() . '&game_code=HERITAGE');

        $heritageScoped->assertOk();
        $this->assertSame(0, $heritageScoped->json('gross_stakes_kobo'));
    }

    public function test_daily_summary_defaults_to_today_when_no_date_is_given(): void
    {
        $token = $this->institutionToken('finance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/daily-summary');

        $response->assertOk()->assertJson(['date' => now()->toDateString()]);
    }

    // ── Player protection: real cross-player responsible-gaming state ─────────

    public function test_active_self_exclusions_are_listed_across_players(): void
    {
        $player = $this->player();
        app(\App\Domain\ResponsibleGaming\ProtectionService::class)->selfExclude($player, 'exclude-6m');
        $token = $this->institutionToken('compliance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/player-protection/exclusions');

        $response->assertOk();
        $exclusions = $response->json('exclusions');
        $this->assertCount(1, $exclusions);
        $this->assertSame($player->id, $exclusions[0]['player_id']);
        $this->assertSame('self-exclusion', $exclusions[0]['type']);
    }

    public function test_a_player_who_has_reached_a_stake_limit_is_flagged_reached(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        app(\App\Domain\ResponsibleGaming\LimitsService::class)->updateLimit($player, 'stake-daily', 50_000);
        app(CreateTicket::class)->create($player, ['B'], 50_000, 'protection-overview-test-1');
        $token = $this->institutionToken('compliance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/player-protection/limits');

        $response->assertOk();
        $row = collect($response->json('limits'))->firstWhere('player_id', $player->id);
        $this->assertNotNull($row);
        $this->assertSame('stake-daily', $row['limit_key']);
        $this->assertSame('reached', $row['status']);
    }

    public function test_a_velocity_flag_appears_in_reviews_and_can_be_resolved(): void
    {
        $player = $this->player();
        $flag = \App\Models\VelocityFlag::create([
            'playerId' => $player->id, 'gameCode' => 'BLACKRED',
            'flagType' => 'rapid_stake_escalation', 'detail' => 'Stake tripled within one hour.',
        ]);
        $token = $this->institutionToken('support_agent');

        $open = $this->withToken($token)->getJson('/backoffice/v1/player-protection/reviews');
        $open->assertOk();
        $this->assertCount(1, $open->json('reviews'));

        $resolve = $this->withToken($token)->postJson("/backoffice/v1/velocity-flags/{$flag->id}/resolve");
        $resolve->assertOk()->assertJson(['status' => 'resolved']);

        $afterResolve = $this->withToken($token)->getJson('/backoffice/v1/player-protection/reviews?status=open');
        $this->assertCount(0, $afterResolve->json('reviews'));
        $this->assertDatabaseHas('auditLog', ['action' => 'velocity_flag_resolved', 'targetTable' => 'velocityFlag', 'targetId' => $flag->id]);
    }

    // ── FocusedManagementConsole's real subset ─────────────────────────────────

    public function test_deposits_are_listed_and_filterable_by_status(): void
    {
        $player = $this->player();
        \App\Models\Collection::create(['playerId' => $player->id, 'reference' => 'DEP-TEST-1', 'amountKobo' => 25_000, 'status' => 'paid', 'paidAt' => now()]);
        \App\Models\Collection::create(['playerId' => $player->id, 'reference' => 'DEP-TEST-2', 'amountKobo' => 10_000, 'status' => 'failed']);
        $token = $this->institutionToken('finance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/deposits?status=paid');

        $response->assertOk();
        $deposits = $response->json('deposits');
        $this->assertCount(1, $deposits);
        $this->assertSame('DEP-TEST-1', $deposits[0]['reference']);
    }

    public function test_payouts_are_listed_and_filterable_by_manual_review_required(): void
    {
        $player = $this->player();
        \App\Models\Payout::create([
            'reference' => 'PO-TEST-1', 'playerId' => $player->id, 'kind' => 'withdrawal',
            'amountKobo' => 40_000, 'sourceLabel' => 'Winnings', 'destinationPhone' => $player->msisdn,
            'destinationLabel' => 'OPay wallet', 'manualReviewRequired' => true,
        ]);
        \App\Models\Payout::create([
            'reference' => 'PO-TEST-2', 'playerId' => $player->id, 'kind' => 'withdrawal',
            'amountKobo' => 5_000, 'sourceLabel' => 'Winnings', 'destinationPhone' => $player->msisdn,
            'destinationLabel' => 'OPay wallet', 'manualReviewRequired' => false,
        ]);
        $token = $this->institutionToken('finance');

        $response = $this->withToken($token)->getJson('/backoffice/v1/payouts?manual_review_required=1');

        $response->assertOk();
        $payouts = $response->json('payouts');
        $this->assertCount(1, $payouts);
        $this->assertSame('PO-TEST-1', $payouts[0]['reference']);
    }

    public function test_the_changes_endpoint_filters_by_change_type_for_the_adjustments_view(): void
    {
        $maker = $this->institutionToken('game_ops');
        $player = $this->player();
        $this->withToken($maker)->postJson('/backoffice/v1/changes', [
            'change_type' => 'manual_credit_debit',
            'payload' => ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            'justification' => 'Compensating a support ticket.',
        ])->assertCreated();

        $response = $this->withToken($maker)->getJson('/backoffice/v1/changes?change_type=manual_credit_debit');

        $response->assertOk();
        foreach ($response->json('changes') as $change) {
            $this->assertSame('manual_credit_debit', $change['change_type']);
        }
    }

    public function test_the_heritage_catalogue_is_listed_and_publish_is_refused_without_a_sign_off(): void
    {
        $this->seed(\Database\Seeders\HeritageGameSeeder::class);
        $token = $this->institutionToken('content_editor');

        $list = $this->withToken($token)->getJson('/backoffice/v1/heritage-catalogue');
        $list->assertOk();
        $itemNumber = $list->json('items.0.number');
        $this->assertSame('preview-only', $list->json('items.0.publication_status'));

        $publish = $this->withToken($token)->postJson("/backoffice/v1/heritage-catalogue/{$itemNumber}/publish");
        $publish->assertOk();
        $this->assertNotEmpty($publish->json('gate_errors'));
        $this->assertNull($publish->json('published_at'));
    }

    public function test_updating_a_heritage_catalogue_item_writes_a_real_audit_log_entry(): void
    {
        $this->seed(\Database\Seeders\HeritageGameSeeder::class);
        $token = $this->institutionToken('content_editor');
        $itemNumber = $this->withToken($token)->getJson('/backoffice/v1/heritage-catalogue')->json('items.0.number');

        $this->withToken($token)->patchJson("/backoffice/v1/heritage-catalogue/{$itemNumber}", [
            'culturalDescription' => 'Updated description for testing.',
        ])->assertOk();

        $this->assertDatabaseHas('auditLog', ['action' => 'heritage_catalogue_item_updated', 'targetTable' => 'heritageCatalogueItem', 'targetId' => $itemNumber]);
    }

    public function test_a_system_admin_can_create_suspend_and_list_institution_users(): void
    {
        $token = $this->institutionToken('system_admin');

        $create = $this->withToken($token)->postJson('/backoffice/v1/institution-users', [
            'email' => 'new.operator@betplus.test', 'display_name' => 'New Operator', 'role' => 'support_agent',
        ]);
        $create->assertCreated();
        $this->assertNotEmpty($create->json('one_time_password'));
        $id = $create->json('id');

        $suspend = $this->withToken($token)->patchJson("/backoffice/v1/institution-users/{$id}", ['status' => 'suspended']);
        $suspend->assertOk()->assertJson(['status' => 'suspended']);

        $list = $this->withToken($token)->getJson('/backoffice/v1/institution-users');
        $list->assertOk();
        $this->assertContains('new.operator@betplus.test', collect($list->json('users'))->pluck('email'));
    }

    public function test_a_non_system_admin_cannot_manage_institution_users(): void
    {
        $token = $this->institutionToken('support_lead');

        $response = $this->withToken($token)->getJson('/backoffice/v1/institution-users');

        $response->assertStatus(403);
    }
}
