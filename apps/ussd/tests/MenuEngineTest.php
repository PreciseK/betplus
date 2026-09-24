<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests;

use Betplus\Ussd\CharacterLimit;
use Betplus\Ussd\MenuEngine;
use Betplus\Ussd\Session\FileSessionStore;
use Betplus\Ussd\Tests\Fakes\FakePlatformClient;
use PHPUnit\Framework\TestCase;

final class MenuEngineTest extends TestCase
{
    private string $sessionDir;
    private FakePlatformClient $platform;
    private FileSessionStore $sessions;
    private MenuEngine $engine;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/betplus-ussd-test-' . bin2hex(random_bytes(6));
        $this->platform = new FakePlatformClient();
        $this->sessions = new FileSessionStore($this->sessionDir);
        $this->engine = new MenuEngine($this->platform, $this->sessions);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sessionDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionDir);
    }

    private function assertFits(\Betplus\Ussd\Screen $screen): void
    {
        $this->assertTrue(CharacterLimit::fits($screen), "Screen exceeds 160 chars: \"{$screen->render()}\" (" . mb_strlen($screen->render()) . ' chars)');
    }

    // ── Registration (REQ-ID-004 — no OTP) ──────────────────────────────────────

    public function test_a_new_number_is_shown_the_opay_name_for_confirmation(): void
    {
        $this->platform->programResponse('identify', ['status' => 'confirm_identity', 'registered_name' => 'Chidinma Eze']);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '');

        $this->assertTrue($screen->continues);
        $this->assertStringContainsString('Chidinma Eze', $screen->render());
        $this->assertFits($screen);
    }

    public function test_confirming_identity_completes_registration_and_reaches_the_main_menu(): void
    {
        $this->platform->programResponse('identify', ['status' => 'confirm_identity', 'registered_name' => 'Chidinma Eze']);
        $this->engine->handleTurn('sess-1', '+2348031234567', '');

        $this->platform->programResponse('completeRegistration', ['status' => 'registered', 'access_token' => 'tok-1']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertTrue($screen->continues);
        $this->assertStringContainsString('Play BlackRed', $screen->render());
    }

    public function test_declining_a_no_wallet_number_ends_with_a_plain_explanation(): void
    {
        $this->platform->programResponse('identify', ['status' => 'no_wallet']);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '');

        $this->assertFalse($screen->continues);
        $this->assertStringContainsString('OPay wallet', $screen->render());
    }

    public function test_an_existing_number_signs_in_directly_with_no_otp_screen(): void
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-1', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 250_000]);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '');

        $this->assertTrue($screen->continues);
        $this->assertStringContainsString('Bal: NGN 2,500', $screen->render());
        // No OTP-anything screen was ever offered — identify() is the whole trust step.
        $this->assertStringNotContainsStringIgnoringCase('otp', $screen->render());
    }

    public function test_split_balance_renders_bonus_balance_when_present(): void
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-bonus', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', [
            'play_balance_kobo' => 70_000,
            'bonus_balance_kobo' => 50_000,
        ]);

        $screen = $this->engine->handleTurn('sess-bonus', '+2348031234567', '');

        $this->assertTrue($screen->continues);
        $this->assertStringContainsString('Bal: NGN 1,200 (Play:700 Bonus:500)', $screen->render());
        $this->assertLessThanOrEqual(160, strlen($screen->render()));
    }

    // ── Invalid input (REQ-USSD-006) ─────────────────────────────────────────

    public function test_invalid_input_re_renders_the_same_screen_with_an_error_prefix(): void
    {
        $this->signIn('sess-1', '+2348031234567');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '9');

        $this->assertTrue($screen->continues);
        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    // ── Main menu (post-Caged renumber) ──────────────────────────────────────

    public function test_the_main_menu_lists_caged_as_option_three_with_no_fund_account_item(): void
    {
        $screen = $this->signInAndReturnScreen('sess-1', '+2348031234567');

        $this->assertStringContainsString('3. Play Caged', $screen->render());
        $this->assertStringContainsString('4. Responsible play', $screen->render());
        $this->assertStringNotContainsStringIgnoringCase('fund account', $screen->render());
        $this->assertFits($screen);
    }

    // ── BlackRed (REQ-HG-005-principle: identical odds via the same platform API) ─

    // ── BlackRed (REQ-HG-005-principle: identical odds via the same platform API) ─

    public function test_a_full_blackred_purchase_flow_calls_the_same_v1_endpoint_web_uses(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // -> blackred_pick
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '121'); // 121 -> BRB
        $this->assertFits($screen);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '500'); // stake
        $this->assertStringContainsString('BRB for NGN 500', $screen->render());

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 10_000_000]);
        $this->platform->programResponse('purchaseBlackRedTicket', ['reference' => 'tkt-1']);
        $this->platform->programResponse('revealBlackRedTicket', ['won' => true, 'net_credit_kobo' => 92_500]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('You won', $result->render());
        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseBlackRedTicket'))[0];
        $this->assertSame(['B', 'R', 'B'], $purchaseCall['args'][1]);
        $this->assertSame(50_000, $purchaseCall['args'][2]);
    }

    public function test_blackred_pick_maps_combinations_correctly(): void
    {
        $i = 0;
        foreach ([
            '121' => 'BRB',
            '212' => 'RBR',
            '221' => 'RRB',
            '1' => 'B',
            '2' => 'R',
            '12121' => 'BRBRB',
        ] as $input => $expected) {
            $i++;
            $inputStr = (string) $input;
            $msisdn = '+234803111000' . $i;
            $sessId = 'sess-br-' . $i;
            $this->signIn($sessId, $msisdn);
            $this->engine->handleTurn($sessId, $msisdn, '1');
            $screen = $this->engine->handleTurn($sessId, $msisdn, $inputStr);
            $this->assertFits($screen);
            $confirm = $this->engine->handleTurn($sessId, $msisdn, '200');
            $this->assertStringContainsString("Confirm: $expected for NGN 200", $confirm->render());
        }
    }

    public function test_blackred_pick_rejects_invalid_inputs(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        foreach (['3', '123', '0', '121212', 'abc'] as $invalid) {
            $screen = $this->engine->handleTurn('sess-1', '+2348031234567', $invalid);
            $this->assertStringStartsWith('Invalid input.', $screen->text);
        }
    }

    public function test_blackred_confirm_funds_from_opay_and_purchases_when_play_balance_is_insufficient(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('collectFromOpay', ['status' => 'paid']);
        $this->platform->programResponse('purchaseBlackRedTicket', ['reference' => 'tkt-direct-paid']);
        $this->platform->programResponse('revealBlackRedTicket', ['won' => false, 'net_credit_kobo' => 0]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('tkt-direct-paid', $result->render());
        $fundingCalls = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'collectFromOpay'));
        $this->assertCount(1, $fundingCalls);
        $this->assertSame(50_000, $fundingCalls[0]['args'][1]);
    }

    public function test_blackred_confirm_ends_the_session_when_opay_wallet_cannot_be_verified(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('collectFromOpay', ['status' => 'wallet_unverified']);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('Could not verify an OPay wallet', $result->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseBlackRedTicket'));
    }

    public function test_blackred_confirm_ends_the_session_when_deposit_needs_manual_review(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('collectFromOpay', ['status' => 'pending_review']);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('needs manual review', $result->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseBlackRedTicket'));
    }

    public function test_cancelling_at_blackred_confirm_returns_to_the_main_menu(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        $this->assertStringContainsString('Play BlackRed', $screen->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseBlackRedTicket'));
    }

    // ── Heritage (REQ-HG-002/013) ────────────────────────────────────────────

    public function test_a_full_heritage_purchase_flow_discloses_match_count_and_never_says_zero(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // -> heritage_pick

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '18734'); // 18734 -> positions [0, 7, 6, 2, 3]
        $this->assertFits($screen);
        $this->engine->handleTurn('sess-1', '+2348031234567', '1000'); // stake

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 10_000_000]);
        $this->platform->programResponse('purchaseHeritageTicket', ['reference' => 'tkt-2']);
        $this->platform->programResponse('revealHeritageTicket', [
            'outcome_tier' => 'TIER_LOSS', 'match_count' => 2, 'net_credit_kobo' => 0,
        ]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('2 of 5 matched', $result->render());
        $this->assertStringNotContainsString('0 of 5', $result->render());

        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseHeritageTicket'))[0];
        $this->assertSame([0, 7, 6, 2, 3], $purchaseCall['args'][1]); // positions are 0-indexed internally
    }

    public function test_heritage_pick_rejects_a_repeated_position(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '18731'); // repeat of position 1

        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    public function test_heritage_pick_rejects_invalid_length_or_digits(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        foreach (['1873', '187345', '18730', 'abcde', '0', '99999'] as $invalid) {
            $screen = $this->engine->handleTurn('sess-1', '+2348031234567', $invalid);
            $this->assertStringStartsWith('Invalid input.', $screen->text);
        }
    }

    // ── Caged (Option B — Escape Count) ──────────────────────────────────────

    public function test_a_full_caged_win_flow_calls_the_same_v1_endpoint_web_uses(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '3'); // -> caged_pick
        $this->assertFits($screen);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // target 2 birds
        $this->assertFits($screen);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '200'); // stake
        $this->assertStringContainsString('Target: 2 Birds', $screen->render());
        $this->assertStringContainsString('Stake: NGN 200', $screen->render());
        $this->assertFits($screen);

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 10_000_000]);
        $this->platform->programResponse('purchaseCagedTicket', ['reference' => 'tkt-cg-1']);
        $this->platform->programResponse('revealCagedTicket', ['won' => true, 'escaped_birds' => 3, 'net_credit_kobo' => 38_000]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('CAGED WIN!', $result->render());
        $this->assertStringContainsString('3 birds escaped', $result->render());
        $this->assertFits($result);

        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseCagedTicket'))[0];
        $this->assertSame(2, $purchaseCall['args'][1]); // target_birds
        $this->assertSame(20_000, $purchaseCall['args'][2]); // stake_kobo
    }

    public function test_caged_confirm_funds_from_opay_and_purchases_when_play_balance_is_insufficient(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3'); // Caged
        $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // target 2 birds
        $this->engine->handleTurn('sess-1', '+2348031234567', '200'); // stake 20,000 kobo

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('collectFromOpay', ['status' => 'paid']);
        $this->platform->programResponse('purchaseCagedTicket', ['reference' => 'tkt-cg-funded']);
        $this->platform->programResponse('revealCagedTicket', ['won' => true, 'escaped_birds' => 3, 'net_credit_kobo' => 38_000]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('CAGED WIN!', $result->render());
        $this->assertStringContainsString('tkt-cg-funded', $result->render());
        $fundingCalls = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'collectFromOpay'));
        $this->assertSame(20_000, $fundingCalls[0]['args'][1]);
    }

    public function test_caged_confirm_ends_the_session_when_opay_merchant_balance_is_unavailable(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');
        $this->engine->handleTurn('sess-1', '+2348031234567', '200');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('collectFromOpay', ['status' => 'float_unavailable']);
        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('Could not process payment', $result->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseCagedTicket'));
    }

    public function test_a_caged_loss_shows_the_escaped_count_and_lost_stake(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '5'); // target 5 birds
        $this->engine->handleTurn('sess-1', '+2348031234567', '100'); // stake

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 10_000_000]);
        $this->platform->programResponse('purchaseCagedTicket', ['reference' => 'tkt-cg-2']);
        $this->platform->programResponse('revealCagedTicket', ['won' => false, 'escaped_birds' => 1, 'net_credit_kobo' => 0]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('CAGED LOSS!', $result->render());
        $this->assertStringContainsString('Cage dropped at 1 bird(s)!', $result->render());
        $this->assertStringContainsString('Lost: NGN 100', $result->render());
        $this->assertFits($result);
    }

    public function test_caged_pick_rejects_an_out_of_range_target(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '9');

        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    public function test_zero_at_caged_pick_returns_to_the_main_menu(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '0');

        $this->assertStringContainsString('Play Caged', $screen->render());
    }

    public function test_cancelling_at_caged_confirm_returns_to_the_main_menu_without_purchasing(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '200');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        $this->assertStringContainsString('Play Caged', $screen->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseCagedTicket'));
    }

    public function test_a_failed_caged_purchase_shows_a_clean_error_with_no_direct_pay_screen(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '200');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 10_000_000]);
        $this->platform->programResponse('purchaseCagedTicket', []); // no 'reference' => purchase failed

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('Could not place that ticket', $result->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'collectFromOpay'));
    }

    // ── Responsible gambling (REQ-USSD-020: within 2 screens of main menu) ──────

    public function test_take_a_break_is_reachable_within_two_screens_of_the_main_menu(): void
    {
        $this->signIn('sess-1', '+2348031234567');

        $rgMenu = $this->engine->handleTurn('sess-1', '+2348031234567', '4'); // screen 1
        $this->assertStringContainsString('Take a break', $rgMenu->render());

        $breakMenu = $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // screen 2
        $this->assertStringContainsString('24 hours', $breakMenu->render());
        $this->assertFits($breakMenu);
    }

    public function test_state_exclusion_info_never_discourages_enrolment(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '4'); // -> rg_menu
        $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // -> rg_break_menu

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '5'); // -> about state exclusion

        $this->assertStringContainsString('ALL licensed operators', $screen->render());
        $this->assertStringNotContainsStringIgnoringCase('are you sure', $screen->render());
    }

    public function test_self_exclusion_calls_the_platform_and_ends_the_session(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '4');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        $this->platform->programResponse('selfExclude', ['status' => 'self-excluded']);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '4');

        $this->assertFalse($screen->continues);
        $selfExcludeCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'selfExclude'))[0];
        $this->assertSame('self-exclude-6m', $selfExcludeCall['args'][1]);
    }

    // ── Session resume (REQ-USSD-004) ────────────────────────────────────────

    public function test_redialling_within_the_resume_window_offers_to_continue(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // enters blackred_length

        $resumed = $this->engine->handleTurn('sess-2', '+2348031234567', '');

        $this->assertStringContainsString('Continue your last play', $resumed->render());
    }

    public function test_declining_resume_starts_a_fresh_welcome(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-2', '+2348031234567', '');

        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-2', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-2', '+2348031234567', '2');

        $this->assertStringContainsString('Play BlackRed', $screen->render());
    }

    // ── Audit mirror (REQ-USSD-003) ──────────────────────────────────────────

    public function test_every_turn_is_mirrored_to_the_platform_for_audit(): void
    {
        $this->signIn('sess-1', '+2348031234567');

        $syncCalls = array_filter($this->platform->calls, fn ($c) => $c['method'] === 'syncSession');
        $this->assertNotEmpty($syncCalls);
    }

    // ── OPay In-Session PIN Challenge Flow ──────────────────────────────────

    public function test_shortfall_triggers_opay_pin_entry_and_completes_purchase_on_valid_pin(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // main menu -> blackred_pick
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // pick Black
        $this->engine->handleTurn('sess-1', '+2348031234567', '500'); // 500 Naira

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0, 'bonus_balance_kobo' => 0]);
        $this->platform->programResponse('initFunding', [
            'status' => 'pending',
            'action_type' => 'INPUT_PIN',
            'order_no' => '2609230000000001',
        ]);

        $pinScreen = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertTrue($pinScreen->continues);
        $this->assertStringContainsString('Enter your 4-digit OPay PIN', $pinScreen->render());
        $this->assertStringContainsString('NGN 500', $pinScreen->render());
        $this->assertFits($pinScreen);

        $this->platform->programResponse('submitFundingPin', ['status' => 'paid']);
        $this->platform->programResponse('purchaseBlackRedTicket', ['reference' => 'tkt-opay-1']);
        $this->platform->programResponse('revealBlackRedTicket', [
            'won' => true,
            'net_credit_kobo' => 98_000,
        ]);

        $finalScreen = $this->engine->handleTurn('sess-1', '+2348031234567', '1234');

        $this->assertFalse($finalScreen->continues);
        $this->assertStringContainsString('You won! Net credit NGN 980', $finalScreen->render());
        $this->assertStringContainsString('Ref: tkt-opay-1', $finalScreen->render());

        $pinCalls = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'submitFundingPin'));
        $this->assertCount(1, $pinCalls);
        $this->assertSame('2609230000000001', $pinCalls[0]['args'][1]);
        $this->assertSame('1234', $pinCalls[0]['args'][2]);
    }

    public function test_invalid_pin_format_prompts_re_entry(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $this->platform->programResponse('initFunding', [
            'status' => 'pending',
            'action_type' => 'INPUT_PIN',
            'order_no' => '2609230000000001',
        ]);

        $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $retryScreen = $this->engine->handleTurn('sess-1', '+2348031234567', '12');

        $this->assertTrue($retryScreen->continues);
        $this->assertStringStartsWith('Invalid input.', $retryScreen->text);
        $this->assertFits($retryScreen);
    }

    public function test_declined_pin_terminates_with_explanation(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '500');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $this->platform->programResponse('initFunding', [
            'status' => 'pending',
            'action_type' => 'INPUT_PIN',
            'order_no' => '2609230000000001',
        ]);

        $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->platform->programResponse('submitFundingPin', ['status' => 'pin_incorrect']);

        $declinedScreen = $this->engine->handleTurn('sess-1', '+2348031234567', '9999');

        $this->assertFalse($declinedScreen->continues);
        $this->assertStringContainsString('Incorrect PIN entered', $declinedScreen->render());
    }

    private function signIn(string $sessionId, string $msisdn): void
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-1', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $this->engine->handleTurn($sessionId, $msisdn, '');
    }

    private function signInAndReturnScreen(string $sessionId, string $msisdn): \Betplus\Ussd\Screen
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-1', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);

        return $this->engine->handleTurn($sessionId, $msisdn, '');
    }
}
