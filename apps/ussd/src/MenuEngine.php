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

    /** targetBirds => display multiplier, from docs/caged-ussd-complete-flows.md's Escape Count table. */
    // Tier 1 recalibrated 2026-09-14 (1.25x -> 1.20x) to match CagedGameSeeder's
    // already-recalibrated tier, which cleared the tightened 8800bp RTP ceiling.
    private const CAGED_ODDS = [1 => 1.20, 2 => 1.90, 3 => 3.80, 4 => 7.50, 5 => 18.00];

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
            'caged_pick' => $this->screenCagedPick($session, $input),
            'caged_stake' => $this->screenCagedStake($session, $input),
            'caged_confirm' => $this->screenCagedConfirm($session, $input),
            'opay_pin_entry' => $this->screenOpayPinEntry($session, $input),
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
            $session->screen = 'blackred_pick';
            $session->data['brPicks'] = [];

            return Screen::continue("BlackRed (1=Black, 2=Red)\nEnter 1-5 picks (e.g. 121 for BRB):");
        }
        if ($input === '2') {
            $session->screen = 'heritage_pick';
            $session->data['heritagePicks'] = [];

            return Screen::continue("Heritage\nEnter 5 positions (1-9, e.g. 18734):");
        }
        if ($input === '3') {
            $session->screen = 'caged_pick';

            return $this->screenCagedPick($session, '');
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
            $playKobo = (int) ($wallet['play_balance_kobo'] ?? 0);
            $bonusKobo = (int) ($wallet['bonus_balance_kobo'] ?? 0);
            $totalBal = number_format(($playKobo + $bonusKobo) / 100, 0);

            $balLine = $bonusKobo > 0
                ? "Bal: NGN $totalBal (Play:" . number_format($playKobo / 100, 0) . " Bonus:" . number_format($bonusKobo / 100, 0) . ")"
                : "Bal: NGN $totalBal";

            return Screen::continue("Betplus\n$balLine\n1. Play BlackRed\n2. Play Heritage\n3. Play Caged\n4. Responsible play\n0. Exit");
        }

        return $this->errorPrefixed($session, 'main_menu', '1. BlackRed 2. Heritage 3. Caged 4. RG tools 0. Exit');
    }

    // ── BlackRed ─────────────────────────────────────────────────────────────

    private function screenBlackRedLength(Session $session, string $input): Screen
    {
        $session->screen = 'blackred_pick';
        $session->data['brPicks'] = [];

        return $this->screenBlackRedPick($session, $input);
    }

    private function screenBlackRedPick(Session $session, string $input): Screen
    {
        if ($input === '') {
            return Screen::continue("BlackRed (1=Black, 2=Red)\nEnter 1-5 picks (e.g. 121 for BRB):");
        }

        $raw = trim($input);
        $cleaned = strtoupper((string) preg_replace('/[\s,]+/', '', $raw));
        $mapped = strtr($cleaned, ['1' => 'B', '2' => 'R']);

        if (!preg_match('/^[BR]{1,5}$/', $mapped)) {
            return $this->errorPrefixed($session, 'blackred_pick', "Enter 1-5 picks (1=Black, 2=Red, e.g. 121):");
        }

        /** @var list<string> $picks */
        $picks = str_split($mapped);
        $session->data['brPicks'] = $picks;
        $session->data['brLength'] = count($picks);
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

        $stakeKobo = (int) $session->data['brStakeKobo'];
        $funding = $this->resolveFunding($session, $stakeKobo, 'blackred');
        if ($funding !== null) {
            return $funding;
        }

        return $this->completeBlackRedPurchase($session);
    }

    private function completeBlackRedPurchase(Session $session): Screen
    {
        /** @var list<string> $picks */
        $picks = $session->data['brPicks'];
        $stakeKobo = (int) $session->data['brStakeKobo'];
        $idempotencyKey = 'ussd-br-' . $session->sessionId;

        $purchase = $this->platform->purchaseBlackRedTicket((string) $session->accessToken, $picks, $stakeKobo, $idempotencyKey);

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
        if ($input === '') {
            return Screen::continue("Heritage\nEnter 5 positions (1-9, e.g. 18734):");
        }

        $raw = trim($input);
        $cleaned = (string) preg_replace('/[\s,]+/', '', $raw);

        if (!preg_match('/^[1-9]{5}$/', $cleaned)) {
            return $this->errorPrefixed($session, 'heritage_pick', "Enter 5 unique positions (1-9, e.g. 18734):");
        }

        $digits = str_split($cleaned);
        if (count(array_unique($digits)) !== 5) {
            return $this->errorPrefixed($session, 'heritage_pick', "Positions must be unique (1-9, e.g. 18734):");
        }

        /** @var list<int> $picks */
        $picks = array_map(fn (string $d) => ((int) $d) - 1, $digits);
        $session->data['heritagePicks'] = $picks;
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

        $stakeKobo = (int) $session->data['hgStakeKobo'];
        $funding = $this->resolveFunding($session, $stakeKobo, 'heritage');
        if ($funding !== null) {
            return $funding;
        }

        return $this->completeHeritagePurchase($session);
    }

    private function completeHeritagePurchase(Session $session): Screen
    {
        /** @var list<int> $picks */
        $picks = $session->data['heritagePicks'];
        $stakeKobo = (int) $session->data['hgStakeKobo'];
        $idempotencyKey = 'ussd-hg-' . $session->sessionId;

        $purchase = $this->platform->purchaseHeritageTicket((string) $session->accessToken, $picks, $stakeKobo, $idempotencyKey);

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

    // ── Caged (Option B — Escape Count) ─────────────────────────────────────

    private function screenCagedPick(Session $session, string $input): Screen
    {
        if ($input === '0') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input === '') {
            return Screen::continue("Caged: Birds Escaping\n1. 1 Bird  (1.20x)\n2. 2 Birds (1.90x)\n3. 3 Birds (3.80x)\n4. 4 Birds (7.50x)\n5. 5 Birds (18.0x)\n0. Back");
        }
        if (!in_array($input, ['1', '2', '3', '4', '5'], true)) {
            return $this->errorPrefixed($session, 'caged_pick', 'Pick 1-5 birds, or 0 to go back.');
        }

        $session->data['cagedTarget'] = (int) $input;
        $session->screen = 'caged_stake';

        return Screen::continue('Enter stake in Naira (e.g. 200):');
    }

    private function screenCagedStake(Session $session, string $input): Screen
    {
        $stakeKobo = $this->nairaToKobo($input);
        if ($stakeKobo === null) {
            return $this->errorPrefixed($session, 'caged_stake', 'Enter stake in Naira (e.g. 200):');
        }

        $session->data['cgStakeKobo'] = $stakeKobo;
        $session->screen = 'caged_confirm';

        return Screen::continue($this->cagedConfirmText($session));
    }

    private function screenCagedConfirm(Session $session, string $input): Screen
    {
        if ($input === '2') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input !== '1') {
            return $this->errorPrefixed($session, 'caged_confirm', $this->cagedConfirmText($session));
        }

        $stakeKobo = (int) $session->data['cgStakeKobo'];
        $funding = $this->resolveFunding($session, $stakeKobo, 'caged');
        if ($funding !== null) {
            return $funding;
        }

        return $this->completeCagedPurchase($session);
    }

    private function completeCagedPurchase(Session $session): Screen
    {
        $target = (int) $session->data['cagedTarget'];
        $stakeKobo = (int) $session->data['cgStakeKobo'];
        $idempotencyKey = 'ussd-cg-' . $session->sessionId;

        $purchase = $this->platform->purchaseCagedTicket((string) $session->accessToken, $target, $stakeKobo, $idempotencyKey);

        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Please try again.');
        }

        $reveal = $this->platform->revealCagedTicket((string) $session->accessToken, (string) $purchase['reference']);
        $won = (bool) ($reveal['won'] ?? false);
        $escaped = (int) ($reveal['escaped_birds'] ?? 0);
        $net = number_format((int) ($reveal['net_credit_kobo'] ?? 0) / 100, 0);
        $naira = number_format(((int) $session->data['cgStakeKobo']) / 100, 0);

        $this->platform->notifyTicketSms((string) $session->accessToken, (string) $purchase['reference']);

        if ($won) {
            return Screen::end("CAGED WIN!\n$escaped birds escaped the cage!\nYour Target: $target Birds\nWon: NGN $net\nRef: {$purchase['reference']}");
        }

        return Screen::end("CAGED LOSS!\nCage dropped at $escaped bird(s)!\nYour Target: $target Birds\nLost: NGN $naira\nRef: {$purchase['reference']}");
    }

    private function cagedConfirmText(Session $session): string
    {
        $target = (int) $session->data['cagedTarget'];
        $odds = self::CAGED_ODDS[$target];
        $stakeKobo = (int) $session->data['cgStakeKobo'];
        $naira = number_format($stakeKobo / 100, 0);
        $win = number_format(($stakeKobo * $odds) / 100, 0);

        return "Confirm Caged Bet:\nTarget: $target Birds\nOdds: " . number_format($odds, 2) . "x\nStake: NGN $naira\nWin: NGN $win\n1. Confirm & Play\n2. Cancel";
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

    // ── Direct-pay funding (in-session PIN authorization or direct settlement) ───

    private function resolveFunding(Session $session, int $stakeKobo, string $gameKey): ?Screen
    {
        try {
            $wallet = $this->platform->wallet((string) $session->accessToken);
        } catch (\Throwable) {
            // Let the purchase attempt itself fail cleanly rather than breaking the
            // menu flow on a pre-flight check exception.
            return null;
        }

        if (!isset($wallet['play_balance_kobo'])) {
            return null;
        }

        $playKobo = (int) $wallet['play_balance_kobo'];
        $bonusKobo = (int) ($wallet['bonus_balance_kobo'] ?? 0);
        $availableKobo = $playKobo + $bonusKobo;

        if ($playKobo > 0 && $availableKobo >= $stakeKobo) {
            return null;
        }

        $shortfallKobo = max($stakeKobo - $availableKobo, 0);
        $amountKobo = $shortfallKobo > 0 ? $shortfallKobo : $stakeKobo;

        try {
            $result = $this->platform->initFunding((string) $session->accessToken, $amountKobo, 'ussd-fund-' . $session->sessionId);
        } catch (\Throwable) {
            $result = null;
        }

        if (!isset($result['status']) && !isset($result['action_type'])) {
            // Fallback to legacy collectFromOpay if initFunding not programmed or returned empty
            $result = $this->platform->collectFromOpay((string) $session->accessToken, $amountKobo, 'ussd-fund-' . $session->sessionId);
        }

        if (($result['status'] ?? '') === 'paid') {
            return null;
        }

        if (($result['action_type'] ?? '') === 'INPUT_PIN' || (($result['status'] ?? '') === 'pending' && isset($result['order_no']))) {
            $session->data['opayOrderNo'] = (string) ($result['order_no'] ?? '');
            $session->data['pendingGame'] = $gameKey;
            $session->data['fundingAmountKobo'] = $amountKobo;
            $session->screen = 'opay_pin_entry';
            $naira = number_format($amountKobo / 100, 0);

            return Screen::continue("Enter your 4-digit OPay PIN to authorise NGN $naira:");
        }

        return Screen::end($this->fundingFailureMessage((string) ($result['status'] ?? 'error')));
    }

    private function screenOpayPinEntry(Session $session, string $input): Screen
    {
        if ($input === '') {
            $naira = number_format(((int) ($session->data['fundingAmountKobo'] ?? 0)) / 100, 0);

            return Screen::continue("Enter your 4-digit OPay PIN to authorise NGN $naira:");
        }

        if (!ctype_digit($input) || strlen($input) !== 4) {
            return $this->errorPrefixed($session, 'opay_pin_entry', 'Enter your 4-digit OPay PIN:');
        }

        $orderNo = (string) ($session->data['opayOrderNo'] ?? '');
        $pinResult = $this->platform->submitFundingPin((string) $session->accessToken, $orderNo, $input);

        if (($pinResult['status'] ?? '') === 'paid') {
            $game = (string) ($session->data['pendingGame'] ?? '');

            return match ($game) {
                'blackred' => $this->completeBlackRedPurchase($session),
                'heritage' => $this->completeHeritagePurchase($session),
                'caged' => $this->completeCagedPurchase($session),
                default => Screen::end('Payment successful. Please dial back in to play.'),
            };
        }

        return Screen::end($this->fundingFailureMessage((string) ($pinResult['status'] ?? 'error')));
    }

    private function fundingFailureMessage(string $status): string
    {
        return match ($status) {
            'limit_exceeded' => 'This would exceed your deposit limit. Adjust your limits on the Betplus app/website.',
            'protection_active' => 'Deposits are currently paused on your account.',
            'registry_unavailable' => 'Could not verify your account right now. Please try again shortly.',
            'wallet_unverified' => 'Could not verify an OPay wallet for this phone number. Please try again later.',
            'float_unavailable' => 'Could not process payment right now. Please try again shortly.',
            'pending_review' => 'This amount needs manual review before it can be credited. Try a smaller amount or check back shortly.',
            'pin_incorrect' => 'Incorrect PIN entered. Transaction cancelled.',
            'expired' => 'Payment session timed out. Please try again.',
            default => 'Could not process payment. Please try again shortly.',
        };
    }
}
