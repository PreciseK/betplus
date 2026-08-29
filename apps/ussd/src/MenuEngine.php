<?php

declare(strict_types=1);

namespace Betplus\Ussd;

use Betplus\Ussd\Session\Session;
use Betplus\Ussd\Session\SessionStore;

/**
 * The whole USSD conversation, one turn at a time. REQ-USSD-006 — invalid input
 * always re-renders the SAME screen with a one-line error prefix, never a dead end.
 * REQ-USSD-004 — a fresh "welcome" turn within the resume window offers to continue
 * the caller's last play instead of starting over.
 *
 * Response envelope assumes the Africa's Talking convention (CON continues, END
 * terminates) — the most common shape among Nigerian USSD aggregators. No
 * aggregator is actually contracted (PRD's C-xx items are unconfirmed); swap
 * Screen::render()'s prefix convention for whichever one is, same situation as
 * OpayGateway's unconfirmed Collections API fields.
 *
 * English only in this pass. REQ-USSD-002/REQ-QA-013 want every screen validated at
 * <=160 characters in every supported locale, Pidgin included since it "is
 * frequently longer than the English source" — CharacterLimit + the screen-fixture
 * test enforce that ceiling for the English strings that exist, but no locale
 * switching or Pidgin/Yoruba/Igbo/Hausa copy was built. Real translation is content
 * work this pass doesn't fabricate, not silently skipped — see sprint-status.yaml.
 */
final class MenuEngine
{
    private const RESUME_WINDOW_SECONDS = 10 * 60;

    public function __construct(
        private readonly PlatformClientInterface $platform,
        private readonly SessionStore $sessions,
    ) {
    }

    public function handleTurn(string $sessionId, string $msisdn, string $rawInput): Screen
    {
        $input = $this->lastSegment($rawInput);
        $session = $this->sessions->get($sessionId, $msisdn) ?? $this->resumeOrBegin($sessionId, $msisdn);

        $screen = $this->dispatch($session, $input);

        if ($screen->continues) {
            $session->lastTouchedAt = time();
            $this->sessions->put($session);
        } else {
            $this->sessions->delete($sessionId, $msisdn);
        }

        $this->platform->syncSession($sessionId, $msisdn, $session->screen, $input, $screen->continues ? 'active' : 'ended');

        return $screen;
    }

    /**
     * Africa's Talking sends the FULL history of this session's input, `*`-joined
     * ("1*2*50000"); this app keeps its own server-side state (Session), so only the
     * newest keystroke since the last screen matters.
     */
    private function lastSegment(string $rawInput): string
    {
        if ($rawInput === '') {
            return '';
        }
        $parts = explode('*', $rawInput);

        return (string) end($parts);
    }

    private function resumeOrBegin(string $sessionId, string $msisdn): Session
    {
        $recent = $this->sessions->mostRecentFor($msisdn);
        if ($recent !== null && (time() - $recent->lastTouchedAt) <= self::RESUME_WINDOW_SECONDS) {
            // REQ-USSD-004 — offer resume via a fresh session row pointed at the same
            // screen; the caller redialled, so this IS a new sessionId from the gateway.
            $resumed = new Session($sessionId, $msisdn, 'resume_offer', $recent->data, $recent->accessToken, time());
            $resumed->data['resumeScreen'] = $recent->screen;

            return $resumed;
        }

        return Session::begin($sessionId, $msisdn);
    }

    private function dispatch(Session $session, string $input): Screen
    {
        return match ($session->screen) {
            'resume_offer' => $this->screenResumeOffer($session, $input),
            'welcome' => $this->screenWelcome($session, $input),
            'registration_confirm' => $this->screenRegistrationConfirm($session, $input),
            'main_menu' => $this->screenMainMenu($session, $input),
            'blackred_length' => $this->screenBlackRedLength($session, $input),
            'blackred_pick' => $this->screenBlackRedPick($session, $input),
            'blackred_stake' => $this->screenBlackRedStake($session, $input),
            'blackred_confirm' => $this->screenBlackRedConfirm($session, $input),
            'heritage_pick' => $this->screenHeritagePick($session, $input),
            'heritage_stake' => $this->screenHeritageStake($session, $input),
            'heritage_confirm' => $this->screenHeritageConfirm($session, $input),
            'fund_amount' => $this->screenFundAmount($session, $input),
            'fund_otp' => $this->screenFundOtp($session, $input),
            'rg_menu' => $this->screenRgMenu($session, $input),
            'rg_break_menu' => $this->screenRgBreakMenu($session, $input),
            default => Screen::end('Session error. Please dial again.'),
        };
    }

    // ── Identity ─────────────────────────────────────────────────────────────

    private function screenResumeOffer(Session $session, string $input): Screen
    {
        if ($input === '') {
            // Just arrived at this screen — render the prompt, don't treat "no
            // input yet" as an answer.
            return Screen::continue("Continue your last play?\n1. Yes\n2. No, start over");
        }
        if ($input === '1') {
            $session->screen = (string) $session->data['resumeScreen'];
            unset($session->data['resumeScreen']);

            return $this->dispatch($session, '');
        }
        if ($input === '2') {
            $session->data = [];

            return $this->screenWelcome($session, '');
        }

        return $this->errorPrefixed($session, 'resume_offer',
            "Continue your last play?\n1. Yes\n2. No, start over");
    }

    private function screenWelcome(Session $session, string $input): Screen
    {
        $result = $this->platform->identify($session->msisdn);
        $status = $result['status'] ?? 'error';

        if ($status === 'signed_in') {
            $session->accessToken = (string) $result['access_token'];
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }

        if ($status === 'confirm_identity') {
            $session->data['registeredName'] = (string) $result['registered_name'];
            $session->screen = 'registration_confirm';

            return Screen::continue("Welcome to Betplus!\nIs this you?\n" . $result['registered_name'] . "\n1. Yes\n2. No, exit");
        }

        if ($status === 'no_wallet') {
            return Screen::end("A Betplus account needs an OPay wallet first. Open one, then dial back in.");
        }

        return Screen::end('Sorry, we could not reach Betplus. Please try again shortly.');
    }

    private function screenRegistrationConfirm(Session $session, string $input): Screen
    {
        if ($input === '1') {
            $result = $this->platform->completeRegistration($session->msisdn);
            if (($result['status'] ?? '') !== 'registered') {
                return Screen::end('Registration could not be completed. Please try again later.');
            }
            $session->accessToken = (string) $result['access_token'];
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input === '2') {
            return Screen::end('No problem. Dial back in whenever you are ready.');
        }

        return $this->errorPrefixed($session, 'registration_confirm',
            'Is this you?\n' . $session->data['registeredName'] . "\n1. Yes\n2. No, exit");
    }

    // ── Main menu ────────────────────────────────────────────────────────────

    private function screenMainMenu(Session $session, string $input): Screen
    {
        if ($input === '1') {
            $session->screen = 'blackred_length';

            return Screen::continue("BlackRed\nHow many picks? (1-5)");
        }
        if ($input === '2') {
            $session->screen = 'heritage_pick';
            $session->data['heritagePicks'] = [];

            return Screen::continue("Heritage\nPick 5 of 9 positions (1-9), one at a time.\nEnter position 1 of 5:");
        }
        if ($input === '3') {
            $session->screen = 'fund_amount';

            return Screen::continue('Fund account\nEnter amount in Naira (e.g. 1000):');
        }
        if ($input === '4') {
            $session->screen = 'rg_menu';

            return $this->screenRgMenu($session, '');
        }
        if ($input === '0' || $input === '') {
            if ($input === '0') {
                return Screen::end('Thank you for playing Betplus responsibly.');
            }
            $wallet = $this->platform->wallet((string) $session->accessToken);
            $bal = number_format((int) ($wallet['play_balance_kobo'] ?? 0) / 100, 0);

            return Screen::continue("Betplus\nBal: NGN $bal\n1. Play BlackRed\n2. Play Heritage\n3. Fund account\n4. Responsible play\n0. Exit");
        }

        return $this->errorPrefixed($session, 'main_menu', '1. BlackRed 2. Heritage 3. Fund 4. RG tools 0. Exit');
    }

    // ── BlackRed ─────────────────────────────────────────────────────────────

    private function screenBlackRedLength(Session $session, string $input): Screen
    {
        $length = (int) $input;
        if ($length < 1 || $length > 5) {
            return $this->errorPrefixed($session, 'blackred_length', 'How many picks? Enter 1-5.');
        }

        $session->data['brLength'] = $length;
        $session->data['brPicks'] = [];
        $session->screen = 'blackred_pick';

        return Screen::continue("Pick 1 of $length: 1=Black 2=Red");
    }

    private function screenBlackRedPick(Session $session, string $input): Screen
    {
        /** @var list<string> $picks */
        $picks = $session->data['brPicks'];
        $length = (int) $session->data['brLength'];

        if ($input !== '1' && $input !== '2') {
            $next = count($picks) + 1;

            return $this->errorPrefixed($session, 'blackred_pick', "Pick $next of $length: 1=Black 2=Red");
        }

        $picks[] = $input === '1' ? 'B' : 'R';
        $session->data['brPicks'] = $picks;

        if (count($picks) < $length) {
            $next = count($picks) + 1;

            return Screen::continue("Pick $next of $length: 1=Black 2=Red");
        }

        $session->screen = 'blackred_stake';

        return Screen::continue('Enter stake in Naira (e.g. 500):');
    }

    private function screenBlackRedStake(Session $session, string $input): Screen
    {
        $stakeKobo = $this->nairaToKobo($input);
        if ($stakeKobo === null) {
            return $this->errorPrefixed($session, 'blackred_stake', 'Enter stake in Naira (e.g. 500):');
        }

        $session->data['brStakeKobo'] = $stakeKobo;
        $session->screen = 'blackred_confirm';
        $picks = implode('', $session->data['brPicks']);
        $naira = number_format($stakeKobo / 100, 0);

        return Screen::continue("Confirm: $picks for NGN $naira\n1. Confirm\n2. Cancel");
    }

    private function screenBlackRedConfirm(Session $session, string $input): Screen
    {
        if ($input === '2') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input !== '1') {
            $picks = implode('', $session->data['brPicks']);
            $naira = number_format(((int) $session->data['brStakeKobo']) / 100, 0);

            return $this->errorPrefixed($session, 'blackred_confirm', "Confirm: $picks for NGN $naira\n1. Confirm\n2. Cancel");
        }

        /** @var list<string> $picks */
        $picks = $session->data['brPicks'];
        $idempotencyKey = 'ussd-br-' . $session->sessionId;
        $purchase = $this->platform->purchaseBlackRedTicket((string) $session->accessToken, $picks, (int) $session->data['brStakeKobo'], $idempotencyKey);

        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Please try again.');
        }

        $reveal = $this->platform->revealBlackRedTicket((string) $session->accessToken, (string) $purchase['reference']);
        $won = (bool) ($reveal['won'] ?? false);
        $net = number_format((int) ($reveal['net_credit_kobo'] ?? 0) / 100, 0);

        // REQ-USSD-005/REQ-NOT-008 — the screen is transient; the SMS is not.
        $this->platform->notifyTicketSms((string) $session->accessToken, (string) $purchase['reference']);

        $result = $won ? "You won! Net credit NGN $net." : 'No win this time.';

        return Screen::end("Result: {$result}\nRef: {$purchase['reference']}\nGood luck next time!");
    }

    // ── Heritage ─────────────────────────────────────────────────────────────

    private function screenHeritagePick(Session $session, string $input): Screen
    {
        /** @var list<int> $picks */
        $picks = $session->data['heritagePicks'];
        $position = (int) $input - 1; // player enters 1-9, board positions are 0-8

        if ($input === '' || $position < 0 || $position > 8 || in_array($position, $picks, true)) {
            $next = count($picks) + 1;

            return $this->errorPrefixed($session, 'heritage_pick', "Enter position $next of 5 (1-9, no repeats):");
        }

        $picks[] = $position;
        $session->data['heritagePicks'] = $picks;

        if (count($picks) < 5) {
            $next = count($picks) + 1;

            return Screen::continue("Enter position $next of 5 (1-9, no repeats):");
        }

        $session->screen = 'heritage_stake';

        return Screen::continue('Enter stake in Naira (e.g. 500):');
    }

    private function screenHeritageStake(Session $session, string $input): Screen
    {
        $stakeKobo = $this->nairaToKobo($input);
        if ($stakeKobo === null) {
            return $this->errorPrefixed($session, 'heritage_stake', 'Enter stake in Naira (e.g. 500):');
        }

        $session->data['hgStakeKobo'] = $stakeKobo;
        $session->screen = 'heritage_confirm';
        /** @var list<int> $picks */
        $picks = $session->data['heritagePicks'];
        $shown = implode(',', array_map(fn (int $p) => $p + 1, $picks));
        $naira = number_format($stakeKobo / 100, 0);

        return Screen::continue("Confirm: positions $shown for NGN $naira\n1. Confirm\n2. Cancel");
    }

    private function screenHeritageConfirm(Session $session, string $input): Screen
    {
        if ($input === '2') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input !== '1') {
            return $this->errorPrefixed($session, 'heritage_confirm', "1. Confirm\n2. Cancel");
        }

        /** @var list<int> $picks */
        $picks = $session->data['heritagePicks'];
        $idempotencyKey = 'ussd-hg-' . $session->sessionId;
        $purchase = $this->platform->purchaseHeritageTicket((string) $session->accessToken, $picks, (int) $session->data['hgStakeKobo'], $idempotencyKey);

        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Please try again.');
        }

        $reveal = $this->platform->revealHeritageTicket((string) $session->accessToken, (string) $purchase['reference']);
        $matchCount = (int) ($reveal['match_count'] ?? 0);
        $tier = (string) ($reveal['outcome_tier'] ?? '');
        $net = number_format((int) ($reveal['net_credit_kobo'] ?? 0) / 100, 0);

        // TIER_SECOND_CHANCE isn't a cash result yet (the draw hasn't run) — that SMS
        // comes from Story 7.9's own notification path once the partner results it;
        // sending a receipt here would be a premature/misleading "you won" mirror.
        if ($tier !== 'TIER_SECOND_CHANCE') {
            $this->platform->notifyTicketSms((string) $session->accessToken, (string) $purchase['reference']);
        }

        // REQ-HG-013/015 — full match count disclosed, never a "0 match" framing.
        $summary = match ($tier) {
            'TIER_JACKPOT', 'TIER_HIGH' => "$matchCount of 5 matched! Net credit NGN $net.",
            'TIER_SECOND_CHANCE' => "$matchCount of 5 matched. You're entered in the next 5/90 draw. SMS confirmation to follow.",
            default => "$matchCount of 5 matched. No prize this time.",
        };

        return Screen::end("Result: {$summary}\nRef: {$purchase['reference']}");
    }

    // ── Funding ──────────────────────────────────────────────────────────────

    private function screenFundAmount(Session $session, string $input): Screen
    {
        $amountKobo = $this->nairaToKobo($input);
        if ($amountKobo === null) {
            return $this->errorPrefixed($session, 'fund_amount', 'Enter amount in Naira (e.g. 1000):');
        }

        $quote = $this->platform->fundingQuote((string) $session->accessToken, $amountKobo);
        if (!isset($quote['quote_id'])) {
            return Screen::end('Funding is not available right now. Please try again shortly.');
        }

        $deposit = $this->platform->createDeposit((string) $session->accessToken, (string) $quote['quote_id']);
        $status = $deposit['status'] ?? 'error';

        // REQ-USSD-013 — routed to verification rather than failing opaquely.
        if ($status === 'bvn_required') {
            return Screen::end('Please complete Tier 1 verification on the Betplus app or website, then fund again.');
        }
        if ($status !== 'otp_required') {
            return Screen::end('Could not start that deposit. Please try again shortly.');
        }

        $session->data['depositId'] = (int) $deposit['collection_id'];
        $session->screen = 'fund_otp';

        return Screen::continue('Enter the OTP OPay sent you:');
    }

    private function screenFundOtp(Session $session, string $input): Screen
    {
        if ($input === '') {
            return $this->errorPrefixed($session, 'fund_otp', 'Enter the OTP OPay sent you:');
        }

        $result = $this->platform->submitDepositOtp((string) $session->accessToken, (int) $session->data['depositId'], $input);
        $status = $result['status'] ?? 'error';

        if ($status === 'paid') {
            return Screen::end('Funded! Your Play Balance has been updated.');
        }

        // REQ-USSD-011 — never left holding an open session waiting on a provider.
        return Screen::end("We'll SMS you when it lands.");
    }

    // ── Responsible gambling (REQ-USSD-020: within 2 screens of main menu) ─────

    private function screenRgMenu(Session $session, string $input): Screen
    {
        if ($input === '1') {
            // Limits are numeric-value edits per key — a real USSD limit-editor needs
            // its own multi-screen key-then-value flow; deferred, see sprint-status.
            return Screen::end('To set limits, please use the Betplus app or website.');
        }
        if ($input === '2') {
            $session->screen = 'rg_break_menu';

            return Screen::continue("Take a break\n1. 24 hours\n2. 7 days\n3. 30 days\n4. Self-exclude 6 months\n5. About state exclusion");
        }
        if ($input === '') {
            return Screen::continue("Responsible play\n1. Set limits\n2. Take a break");
        }

        return $this->errorPrefixed($session, 'rg_menu', "1. Set limits\n2. Take a break");
    }

    private function screenRgBreakMenu(Session $session, string $input): Screen
    {
        $optionId = match ($input) {
            '1' => 'cool-off-24h',
            '2' => 'cool-off-7d',
            '3' => 'cool-off-30d',
            '4' => 'self-exclude-6m',
            default => null,
        };

        if ($input === '5') {
            // REQ-USSD-021 — never discouraging enrolment.
            return Screen::end("A state exclusion covers ALL licensed operators, not just Betplus. Contact your state gaming authority to enrol. It's a strong step, and it's yours to take.");
        }

        if ($optionId === null) {
            return $this->errorPrefixed($session, 'rg_break_menu', "1. 24h 2. 7d 3. 30d 4. Self-exclude 6mo 5. About state exclusion");
        }

        $result = str_starts_with($optionId, 'self-exclude')
            ? $this->platform->selfExclude((string) $session->accessToken, $optionId)
            : $this->platform->startCoolOff((string) $session->accessToken, $optionId);

        if (!isset($result['status'])) {
            return Screen::end('Could not apply that right now. Please try again shortly.');
        }

        return Screen::end('Done. Take care of yourself — see you when you are ready.');
    }

    // ── shared ───────────────────────────────────────────────────────────────

    private function errorPrefixed(Session $session, string $screen, string $body): Screen
    {
        $session->screen = $screen;

        return Screen::continue("Invalid input.\n" . $body);
    }

    private function nairaToKobo(string $input): ?int
    {
        if ($input === '' || !ctype_digit($input)) {
            return null;
        }
        $kobo = (int) $input * 100;

        return $kobo > 0 ? $kobo : null;
    }
}
