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

    // ── Invalid input (REQ-USSD-006) ─────────────────────────────────────────

    public function test_invalid_input_re_renders_the_same_screen_with_an_error_prefix(): void
    {
        $this->signIn('sess-1', '+2348031234567');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '9');

        $this->assertTrue($screen->continues);
        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    // ── BlackRed (REQ-HG-005-principle: identical odds via the same platform API) ─

    public function test_a_full_blackred_purchase_flow_calls_the_same_v1_endpoint_web_uses(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // -> blackred_length
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // 2 picks
        $this->assertFits($screen);
        $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // pick 1: Black
        $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // pick 2: Red
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '500'); // stake
        $this->assertStringContainsString('BR for NGN 500', $screen->render());

        $this->platform->programResponse('purchaseBlackRedTicket', ['reference' => 'tkt-1']);
        $this->platform->programResponse('revealBlackRedTicket', ['won' => true, 'net_credit_kobo' => 92_500]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('You won', $result->render());
        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseBlackRedTicket'))[0];
        $this->assertSame(['B', 'R'], $purchaseCall['args'][1]);
        $this->assertSame(50_000, $purchaseCall['args'][2]);
    }

    public function test_cancelling_at_blackred_confirm_returns_to_the_main_menu(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
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

        foreach (['1', '2', '3', '4', '5'] as $digit) {
            $screen = $this->engine->handleTurn('sess-1', '+2348031234567', $digit);
            $this->assertFits($screen);
        }
        $this->engine->handleTurn('sess-1', '+2348031234567', '1000'); // stake

        $this->platform->programResponse('purchaseHeritageTicket', ['reference' => 'tkt-2']);
        $this->platform->programResponse('revealHeritageTicket', [
            'outcome_tier' => 'TIER_LOSS', 'match_count' => 2, 'net_credit_kobo' => 0,
        ]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('2 of 5 matched', $result->render());
        $this->assertStringNotContainsString('0 of 5', $result->render());

        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseHeritageTicket'))[0];
        $this->assertSame([0, 1, 2, 3, 4], $purchaseCall['args'][1]); // positions are 0-indexed internally
    }

    public function test_heritage_4_of_5_gets_half_stake_back(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');
        foreach (['1', '2', '3', '4', '5'] as $digit) {
            $this->engine->handleTurn('sess-1', '+2348031234567', $digit);
        }
        $this->engine->handleTurn('sess-1', '+2348031234567', '1000');
        $this->platform->programResponse('purchaseHeritageTicket', ['reference' => 'tkt-3']);
        $this->platform->programResponse('revealHeritageTicket', [
            'outcome_tier' => 'TIER_HIGH', 'match_count' => 4, 'net_credit_kobo' => 50_000,
        ]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('4 of 5 matched! Half stake back', $result->render());
        $this->assertStringContainsString('NGN 500', $result->render());
        $this->assertFits($result);
    }

    public function test_heritage_pick_rejects_a_repeated_position(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '2');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '1'); // repeat

        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    // ── Funding (REQ-USSD-010..013) ──────────────────────────────────────────

    public function test_funding_without_tier_1_is_routed_to_verification_not_a_bare_failure(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3'); // -> fund_amount

        $this->platform->programResponse('fundingQuote', ['quote_id' => '100000']);
        $this->platform->programResponse('createDeposit', ['status' => 'bvn_required']);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '1000');

        $this->assertFalse($screen->continues);
        $this->assertStringContainsString('Tier 1 verification', $screen->render());
    }

    public function test_a_deposit_that_does_not_resolve_promptly_closes_the_session_gracefully(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->platform->programResponse('fundingQuote', ['quote_id' => '100000']);
        $this->platform->programResponse('createDeposit', ['status' => 'otp_required', 'collection_id' => 42]);
        $this->engine->handleTurn('sess-1', '+2348031234567', '1000');

        $this->platform->programResponse('submitDepositOtp', ['status' => 'processing']);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '123456');

        $this->assertFalse($screen->continues);
        $this->assertStringContainsString('SMS you when it lands', $screen->render());
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

    private function signIn(string $sessionId, string $msisdn): void
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-1', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $this->engine->handleTurn($sessionId, $msisdn, '');
    }
}
