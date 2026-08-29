# Betplus UX Design Standard

> Status: product UX specification  
> Applies to: player Web/App/USSD experiences and internal back-office workflows  
> Visual system: [`design.md`](./design.md)  
> Product requirements: [`Betplus_Platform_PRD.md`](./Betplus_Platform_PRD.md)

## 1. Experience north star

**Betplus helps an eligible adult choose a game, understand the risk, complete a play, and verify what happened to their money—without ambiguity, pressure, or channel disadvantage.**

The desired emotional sequence is:

1. “I know what this is.”
2. “I know what it costs and what can happen.”
3. “I am in control.”
4. “I can verify the result and my money.”
5. “I know what to do if something is delayed or wrong.”

Pleasure comes after functionality, reliability, usability, and convenience. Visual excitement may never outrank comprehension or player protection.

## 2. Product principles

### One account, one wallet, many games

Identity, balances, activity, settings, limits, and support are platform concepts. Never make a player learn them again per game.

### Money must be boringly clear

Before commitment show stake, possible gross return, applicable tax treatment, balance source, and the action’s exact consequence. After commitment show a durable receipt and status.

### Fast does not mean pressured

Remove redundant steps and latency, not reflection. Never use timers, preselected high stakes, escalating defaults, or visually dominant replay prompts to increase velocity.

### Trust is evidence

Use references, timestamps, provider status, odds, rules, gross/tax/net breakdowns, and recovery paths. Do not substitute badges or slogans for proof.

### Every channel is first class

USSD is the product for Aisha, not a degraded Web flow. Mechanics, odds, wallet state, limits, and results are equivalent across channels even when presentation differs.

### Fail clearly and preserve value

When play must fail closed for geolocation or exclusion checks, explain the temporary block without blaming the player. A created/funded ticket and a won prize are never lost because a screen, app, or USSD session disappears.

### Responsible play is core navigation

Net position, limits, cool-off, self-exclusion, and help are easy to find and visually neutral. Withdrawal remains accessible while play or deposits are blocked.

## 3. Users and design priorities

| Persona | Primary context | Needs | UX risk to avoid |
|---|---|---|---|
| Tunde, 29 | Lagos, Android, frequent bettor | fast play, clear balances, responsive app | speed becoming pressure or opaque repeat play |
| Aisha, 44 | Kano, feature phone, USSD | short sessions, receipts, numeric input | treating USSD as secondary; losing state on timeout |
| Chinedu, 36 | Onitsha, Android, 5/90 literate | draw schedule and verifiable ticket receipt | vague second-chance status |
| Ngozi, 31 | Abuja, Android, occasional Heritage player | cultural care, understandable board | superficial or inaccurate cultural decoration |
| Support Agent | desktop back office | one-call resolution across games | fragmented histories and unexplained provider states |
| Compliance Officer | desktop back office | reconstruct, evidence, export | hidden audit trails or exposed sensitive identity data |
| Finance Operator | desktop back office | float, reconciliation, tax, payout exceptions | decorative dashboards that hide actionable risk |

Research must include users with low digital confidence, feature-phone-only users, screen-reader users, low vision, motor impairments, intermittent data, and English/Pidgin preferences.

## 4. Information architecture

### 4.1 Player Web/App navigation

Use five stable top-level destinations:

1. **Home** — balances, relevant status, continue/current ticket, game entry
2. **Games** — available games, rules, odds, state/channel availability
3. **Wallet** — Play Balance, Winnings Balance, fund, withdraw, turnover progress
4. **Activity** — plays, deposits, withdrawals, second-chance tickets, receipts
5. **Account** — identity/KYC, limits and breaks, notifications, language, help, legal

Mobile uses labeled bottom navigation. Desktop uses persistent labeled navigation. Current location is visible. Do not hide primary navigation behind a desktop hamburger.

Responsible-play entry points appear in Account and contextually on Home/game screens. Net position appears on Home or within one direct action—not buried inside settings.

### 4.2 Key hierarchy

```text
Betplus
├── Home
│   ├── Balance summary
│   ├── Current/pending action
│   ├── Net position (7/30/90 days)
│   └── Game entry
├── Games
│   ├── BlackRed
│   │   ├── Play
│   │   ├── How it works
│   │   └── Odds and rules
│   └── Heritage
│       ├── Play
│       ├── Draw schedule/tickets
│       └── How it works, odds, and cultural catalogue
├── Wallet
│   ├── Fund
│   ├── Withdraw
│   ├── Balances and turnover
│   └── Money activity
├── Activity
│   ├── Plays and receipts
│   ├── Deposits/withdrawals
│   └── Second-chance entries
└── Account
    ├── Identity and verification
    ├── Limits, cool-off, self-exclusion
    ├── Notifications and language
    ├── Help and disputes
    └── Terms, privacy, licensing, and game rules
```

### 4.3 USSD main menu

Keep the PRD order and within 160 characters:

```text
Betplus · Bal ₦2,400
1. Play BlackRed
2. Play Heritage
3. Fund account
4. My account
5. Help
```

Use numeric input only. “Set limits” and “Take a break” are reachable within two screens. Every outcome is mirrored to SMS.

### 4.4 Back-office navigation

Organize navigation into a small set of familiar work areas. The first level is Dashboard, Customers, Finance, Product, Compliance, Insights and Administration. Each work area uses labelled sub-management sets rather than one long mixed list. Customer → Player details contains Current case, Ledger, Tickets, Payments, Tax, Notifications and Responsible play; Support tickets sits in a separate Support set. Each destination shows one primary table or decision surface.

The dashboard is the default landing page for every role. Role-based access removes work areas the operator cannot reach; it does not fill the navigation with disabled links. Security controls such as maker-checker, MFA and audit remain enforced but appear only when they are relevant to an action. No role sees raw NIN/BVN unless explicitly authorized by the separate vault policy.

The header-level game selector is global back-office context. All game-specific figures, tables, queues and links must follow it. Options come from the game registry so future games do not require a new navigation design. **All games** is an explicit aggregate view, never an accidental mixture.

## 5. Global screen hierarchy

For player task screens, use this order:

1. Context: title, game/provider, status
2. Essential state: balances, current step, eligibility issue
3. Main task content
4. Exact commitment summary
5. Primary and secondary actions
6. Rules/help relevant to this decision

Keep navigation and balance placement stable between game screens. Never move the stake or primary confirmation merely to make a layout feel dynamic.

## 6. Critical journeys

### 6.1 Registration and activation

Goal: reach verified Tier 1 with minimum surprise while explaining why identity data is required.

Web/App flow:

1. Enter Nigerian phone number.
2. Verify six-digit OTP.
3. Validate OPay wallet and confirm returned name.
4. Enter date of birth; block under-18 registration.
5. Enter and verify NIN.
6. Check state exclusion registry.
7. Confirm account created and show the next safe action: fund or learn how games work.

Rules:

- Show the progress label (“Step 3 of 5”) and allow safe back navigation.
- Ask only for data needed at the current tier.
- Explain NIN before input: “We use your NIN to verify age/identity and check player-protection exclusions. It is stored separately and never shown in full.”
- Do not claim OPay validation is KYC; it is name and wallet validation.
- Mask phone and identity values after entry.
- If no OPay wallet exists, say it is required and link/give the route to open one. Preserve completed registration progress where lawful.
- Consent to marketing is separate, optional, unchecked, and not part of account completion.
- Completion does not autoplay or drop the player into a stake screen.

USSD follows the PRD flow: confirm the OPay-returned name rather than typing it, use `DDMMYYYY`, accept 11 NIN digits, and keep every screen within 160 characters.

### 6.2 Funding

1. Enter or choose an amount; no maximum is preselected.
2. Show funding method, destination Play Balance, amount, and any charge before confirmation.
3. Send/request OPay OTP where applicable.
4. Show processing state without trapping the session.
5. Confirm with new Play Balance and durable Activity receipt.

If OPay is slow, use: “Your ₦5,000 funding request is still processing. You can leave this screen—we’ll update Activity and send an SMS when it completes.” Do not show success until provider/callback confirmation.

On USSD, stop waiting after the configured threshold and close with the PRD promise that SMS will follow. Never ask the player to repeat a pending request blindly.

### 6.3 BlackRed play

1. Choose 1–5 cards.
2. Choose Black or Red for every position; each choice includes letter and label, not color alone.
3. Enter stake within the configured range.
4. Review exact prediction, stake, true win probability/odds, possible gross return, estimated/applicable tax disclosure, and balance source.
5. Explicitly select **Confirm ₦X stake**.
6. Resolve and reveal the predetermined result.
7. Show factual result and receipt.

Loss state:

- Heading: “Not a win this time.”
- Show “Your prediction” and “Result” side by side or in two labeled rows.
- Show stake and updated Play Balance.
- Primary action: “Back to games” or “Done.”
- “Play again” may be secondary and is removed/suppressed when velocity, limit, or reality-check rules require it.
- Never say “almost,” “so close,” “try a bigger stake,” or “one more.”

Win state:

- Heading: “You won ₦X net.”
- Show gross prize, tax withheld, net Winnings Balance credit, ticket reference, and payout/withdrawal status.
- Celebration is brief and non-flashing. It never obscures the breakdown.

### 6.4 Heritage play

1. Choose a tradition and King/Queen presentation, with equal prominence and no inferred default.
2. Enter/review stake and true outcome rules.
3. Receive a nine-position board and select exactly five; Quick Pick is neutral and server-side.
4. Review stake and selected numbers before commitment.
5. Reveal with a persistent match counter and textual item context.
6. Settle by revealing all nine positions and marking picked/winning states with text/icons/patterns.
7. If second chance applies, show “Entry pending” until a partner reference exists.

The UI must state: “Your choices reveal a result determined when the ticket is created; reveal order does not change the outcome.” Tradition and King/Queen choices are cosmetic and do not affect odds.

For second chance:

- Pending: exact five numbers, target draw if known, Betplus ticket reference, next update expectation.
- Lodged: draw name/time, partner reference, Betplus reference, receipt timestamp.
- Rolled: explain the new draw and why.
- Failed after policy limit: show compensation and Activity entry.
- Always notify the result, win or loss.

### 6.5 Withdrawal

1. Choose eligible source/destination; explain Play versus Winnings Balance and turnover progress.
2. Enter amount.
3. Show destination name/masked account, amount, tax already accounted for or any remaining deduction, and expected timing.
4. Step-up verification when required.
5. Confirm withdrawal.
6. Track Requested → Processing → Paid or Needs attention in Activity.

Never imply a pending provider response is a failure. A failed withdrawal must explain whether funds were returned and where. During cool-off/self-exclusion, withdrawal remains directly accessible.

### 6.6 Limits, cool-off, and self-exclusion

Separate three concepts:

- **Limits:** adjustable deposit, stake, and session boundaries across all games.
- **Cool-off:** 24 hours, 7 days, or 30 days; blocks play and deposit.
- **Self-exclusion:** minimum six months and irreversible for the chosen period.

Rules:

- Lower limit: immediate effect.
- Higher limit: clearly show the 24-hour delay and current limit until activation.
- Show exact start/end time in WAT and affected capabilities.
- Confirm that withdrawal remains available.
- Give equal visual weight to continue and cancel before an irreversible exclusion; once chosen, do not introduce friction designed to reverse the choice.
- Never market to an excluded or cooled-off player and never use return-oriented copy.

### 6.7 Reality check

At configured time/play thresholds, interrupt before the next stake with:

- Session time
- Number of plays
- Total staked
- Net position across all games
- Primary action: “End session”
- Secondary action: “Continue” only when allowed
- Direct link to limits and breaks

No celebration, shame, or loss minimization. The check cannot be dismissed by clicking outside.

### 6.8 Failed-closed eligibility

When geolocation, licensed-state resolution, or registry freshness prevents play:

- Say what is unavailable: “Play is temporarily unavailable.”
- Give a plain, non-accusatory reason at the appropriate disclosure level.
- Preserve withdrawal and Activity access.
- Offer retry only if retry can plausibly help; otherwise provide support/status guidance.
- Do not default to a location or exclusion result.

Example: “We can’t complete the required player-protection check right now, so we can’t start a new game. Your account and withdrawals are still available. Please try again later.”

## 7. Financial clarity standard

### 7.1 Formatting

- User-facing currency: `₦2,400` or `₦2,400.50` when kobo are material.
- Never show raw kobo.
- Use locale-aware formatting and WAT for local timestamps; store/transport timestamps independently.
- Avoid abbreviating money (`₦2.4k`) in commitments, receipts, limits, or reports.
- Align amounts by decimal in tables and use tabular numerals.

### 7.2 Balance language

Always use the canonical terms:

- **Play Balance:** deposited funds available to stake, subject to turnover rules.
- **Winnings Balance:** settled net winnings available under withdrawal rules.

Where relevant, explain transfer/turnover progress in plain language. Never use `PLAYER_PAYOUT`, “cash wallet,” or game-specific wallet names.

### 7.3 Commitment summary

Every money-changing action has a final summary containing:

- Action and amount
- Source and destination
- Relevant game/provider
- Fees/tax or “No fee” when verified
- Expected outcome/timing
- Cancellation or irreversibility note

The final CTA includes the amount. The server remains authoritative; the UI never computes the official balance, prize, eligibility, or tax.

### 7.4 Receipts and disputes

A receipt contains a stable reference, timestamp, amount breakdown, status history, and “Get help with this transaction.” Support deep-links directly to the referenced ticket/transaction without asking the player to retype sensitive data.

## 8. Forms and input

- Use persistent labels and one-column layout for player forms.
- Required fields are explicit; do not mark every field if all are required—state it once at the group level.
- Use correct input modes: `tel` for phone, `numeric` for OTP/NIN, locale-aware decimal numeric for amount.
- Do not split date of birth into three tiny select menus. Use a clear date format example and validate plausibility.
- Preserve non-sensitive entries after recoverable errors.
- Validate format on blur; validate server rules after submit; focus a summary and link each error to its field.
- Never reveal whether a different person’s NIN, phone, or account exists.
- Allow password managers, OTP autofill, copy/paste, and accessible zoom.
- A disabled submit must have an explanation; when possible keep it enabled and explain errors on attempt.

Example errors:

- Phone: “Enter an 11-digit Nigerian phone number, for example 0801 234 5678.”
- OTP: “That code has expired. Request a new code.”
- NIN: “Enter the 11 digits on your NIN.”
- Amount: “Enter an amount from ₦100 to ₦50,000.”
- Provider: “OPay hasn’t confirmed this yet. Don’t submit it again—we’ll update you.”

## 9. System states and recovery

Design each critical flow for these distinct states:

| State | UX requirement |
|---|---|
| Initial | clear purpose and first action |
| Loading | name what is loading; preserve layout |
| Empty | explain why empty and offer one useful action |
| Pending | durable status, timestamp, expectation, safe exit |
| Success | factual confirmation and receipt |
| Recoverable error | preserve input/value and show exact next step |
| Blocking error | explain unavailable capability and alternatives |
| Offline | distinguish local connectivity from provider delay |
| Expired | explain what expired and what remains preserved |
| Partial dependency outage | keep unaffected game/wallet capabilities available |

Do not use an indefinite spinner beyond a short network transition. After a few seconds, replace it with a named status and safe navigation. Never optimistically show deposit, payout, or ticket settlement as complete before server confirmation.

## 10. Notifications and attention

- SMS is the guaranteed transactional channel; push is optional; in-app inbox provides history.
- Request push permission only after explaining a relevant benefit, such as payout or ticket updates.
- Transactional and marketing consent/preferences are separate.
- Marketing is opt-in and suppressed for breaks and all exclusions.
- Quiet hours apply to marketing, not money or ticket receipts.
- Notification text begins with the state and amount, not hype.
- In-app unread badges represent durable items; do not badge every promotional event.

Required transactional messages include OTP, deposit confirmation, funded ticket, gross/tax/net win confirmation, payout, withdrawal, second-chance receipt/result, limit reached, and break/exclusion confirmation.

## 11. Content design

### 11.1 Voice

Betplus is **clear, warm, direct, and accountable**.

- Use everyday words: “money added,” “processing,” “tax withheld,” “try again.”
- Put the important fact first.
- Prefer verbs on buttons.
- Use “we” for system responsibility; never blame the player.
- Be restrained around wins and neutral around losses.
- Do not use gambling euphemisms to conceal stakes, losses, odds, or tax.

### 11.2 Terminology

Use:

- Betplus
- BlackRed “instant fixed-odds prediction”
- Heritage “culture-themed instant-win game”
- Play Balance / Winnings Balance
- Fund account / Withdraw
- Stake / Gross prize / Tax withheld / Net credited
- Ticket reference / Partner reference

Avoid:

- Buzzicash
- “Raffle” for BlackRed
- Bet slip when the object is a Betplus ticket
- Cashout when the action is Withdraw
- Lucky, guaranteed, risk-free, almost won, due a win
- Win-back, chase, boost your luck, go bigger

### 11.3 English and Nigerian Pidgin

English and Nigerian Pidgin are launch locales. Translate meaning, not word order. Pidgin copy must be reviewed by native speakers across target regions and remain respectful in compliance/KYC contexts. Allow 40% text expansion and never bake copy into images.

Use language endonyms in the selector, not flags. Externalize every string, format with locale-aware APIs, and preserve Yoruba/Igbo diacritics with an approved SMS transliteration fallback.

## 12. Accessibility

Target WCAG 2.2 AA across Web and App, including:

- Complete keyboard flow with visible focus and no traps
- Correct names, roles, values, instructions, and status announcements
- Meaningful landmarks, headings, lists, and native controls
- Text plus icon/pattern for Black/Red, game results, and statuses
- Minimum 44×44 CSS px / 48×48 dp targets
- Reflow at 320 CSS px and browser zoom to 400%
- Device text scaling without clipped controls
- Reduced motion that exposes the same result and sequence meaning
- Error summary plus inline errors
- No timeout without warning/extension where the channel permits; USSD state resumes within the specified window
- Captions/transcripts for any instructional media

Game QA must include screen-reader completion of one BlackRed play and one Heritage play. Heritage’s full nine-position result must be navigable as a coherent grid/list after reveal.

## 13. Ethical and responsible design gate

The following are release blockers:

- Maximum or elevated stake selected by default
- Countdown or scarcity around staking or replay
- Loss disguised as a partial success
- Near miss highlighted more strongly than factual results
- Replay visually dominant after a loss
- Required consent bundled with optional marketing
- “Accept” more prominent than “Decline” for optional consent
- Withdrawal harder to find than deposit
- Cool-off/self-exclusion hidden, shamed, or obstructed
- Break/excluded player shown play/deposit promotion
- Odds, fees, tax, or result timing disclosed only after commitment
- Fake social proof, fake live activity, or unverifiable winner claims
- Celebratory animation for a loss or repeated high-intensity win feedback
- Any interface suggesting reveal choice can change a predetermined outcome

## 14. Back-office UX

### 14.1 Player 360

Keep player search, identity/KYC status and both balances as stable context across player-detail routes. Promote Current case, Ledger, Tickets, Payments, Tax, Notifications and Responsible play to the main Customers sidebar. Render only the selected evidence surface—never stack the full dossier or repeat these links in an inner sidebar. Mask sensitive identifiers by default. All operator actions show permission, reason, and audit consequence.

### 14.2 Tables

- Default to useful scoped date ranges; never silently load unbounded history.
- Sticky column headers, sortable labeled columns, filters with visible active state, and CSV export where authorized.
- Right-align financial values; status uses label plus color.
- Preserve filters in the URL and on return navigation.
- Bulk actions show count and require explicit scoped confirmation.
- Empty, loading, partial, stale, and error states are visibly different.

### 14.3 Maker-checker

For prize tables, manual money movements, limits, jurisdiction/tax rules, and catalogue publication:

- Maker sees a before/after diff and supplies justification.
- Checker sees risk context, affected states/games, effective time, and validation results.
- A user cannot approve their own change.
- Rejection requires a reason and preserves the draft.
- Applied changes show immutable actor, approver, version, and timestamp.

### 14.4 Operational dashboards

Every role dashboard begins with **Today at a Glance** and answers four questions in order:

1. What happened today? Show 3–5 plain-language KPIs with a comparison period.
2. How is performance changing? Show one primary trend chart, not a wall of charts.
3. What needs attention? Show a short, role-specific action list ordered by importance.
4. What changed recently? Show a concise activity feed with actor and time.

The default Super Admin view uses Total stakes, Payouts, New players and Needs attention. Commercial performance appears near a clearly labelled Player safety summary. Finance, Compliance, Support, Game Ops, Content and System Admin receive the same composition with role-relevant figures and actions.

Operators can switch from Today to the previous day in one action or select an earlier permitted date directly. The title, KPIs, comparison copy, chart period, action queue and activity feed update as one historical snapshot. The selected date and game remain visible at all times and are preserved in the URL.

Use plain business labels. Prefer “Payouts” to “Payout float”, “Support tickets” to “case queue”, and “Team access” to “identity administration” in navigation and overview copy. Technical permissions, network state, capability names and audit consequences stay out of the main dashboard unless they affect the current task.

Charts require a short text summary, accessible legend, exact tooltips and a disclosed data table. Detailed operational consoles may still lead with their specialist work queue after the operator leaves the dashboard.

## 15. Measurement

Product metrics come from the PRD and must be segmented by channel, game, app version, state, locale, and accessibility-relevant settings where privacy-safe.

Primary UX measures:

- Registration → OPay validation → NIN verification → first funding → first paid play
- USSD completion and end reason
- Funding completion and repeat-attempt rate while pending
- Time to first value
- Payout completion and time to provider confirmation
- Receipt/help access after money events
- Cross-game adoption
- Limit/cool-off tool discoverability and completion
- Error recovery rate by error code
- Task success and comprehension in usability tests

Do not optimize activation, repeat play, or retention in isolation. Pair commercial metrics with complaints, loss comprehension, limit usage, self-exclusion integrity, support contacts, and responsible-play indicators.

Analytics never receives raw MSISDN, NIN, BVN, date of birth, or fine-grained coordinates. Money/outcome events are server-side authoritative.

## 16. Research and validation plan

Before visual polish locks in:

1. Interview 5–8 participants across frequent bettor, occasional player, and feature-phone contexts.
2. Test the mental model of one platform/two balances/two games.
3. Run first-click and tree tests on Games, Wallet, Activity, limits, and support.
4. Test registration/NIN explanation for comprehension and trust.
5. Test one end-to-end Web/App flow and one USSD flow with simulated provider delay.
6. Test BlackRed odds/tax comprehension before confirmation.
7. Test Heritage’s predetermined outcome disclosure and complete-board result.
8. Include screen-reader, low-vision, motor, low-literacy, Pidgin, and poor-connectivity sessions.
9. Conduct cultural review before Heritage art direction or copy is approved.

Minimum moderated task set:

- Register and explain why NIN is required.
- Fund ₦1,000 and identify when it is safe to leave the screen.
- Explain Play Balance versus Winnings Balance.
- Complete a play and state gross, tax, and net result.
- Find a ticket receipt and get help.
- Set a lower weekly limit and take a seven-day break.
- Explain what happens to withdrawal access during the break.

## 17. Anti-AI-slop review

AI-generated designs or code must answer these questions with visible evidence:

- What user decision is this screen supporting?
- What changes in the user’s money or eligibility?
- Which state is server-authoritative?
- What happens on slow OPay, offline, timeout, or duplicate submission?
- Where is the receipt or reference?
- How does a keyboard/screen-reader user complete it?
- What does reduced motion show?
- How does the screen fit at 320 px and with 40% longer text?
- Does the copy disclose odds, tax, timing, and predetermined outcome at the right moment?
- Is any visual treatment encouraging chasing, urgency, or near-miss fixation?
- Is this a platform pattern or a game-only pattern, and can a third game reuse the shell?

Reject work that only presents an attractive default state. A complete feature includes task logic, content, all system states, accessibility behavior, responsive behavior, analytics intent, and ethical review.

## 18. UX definition of done

- [ ] The user goal, eligibility, and success condition are documented.
- [ ] The flow matches the PRD and uses canonical terms.
- [ ] Stake, odds, tax, destination, and timing are clear before commitment.
- [ ] Duplicate, pending, timeout, session loss, and provider failure paths preserve money and progress.
- [ ] All loading, empty, error, blocked, success, and recovery states are designed.
- [ ] A durable receipt/reference exists for every ticket and money action.
- [ ] Mobile, desktop, USSD-equivalent, keyboard, screen reader, zoom, and reduced-motion paths are covered.
- [ ] English and Pidgin are reviewed; diacritics and text expansion pass.
- [ ] Responsible-play tools remain findable; withdrawal remains accessible when required.
- [ ] No dark pattern, fake proof, near-miss emphasis, or loss celebration is present.
- [ ] Analytics is privacy-safe and measures both completion and comprehension/harm signals.
- [ ] Five representative users can complete and explain the task without moderator rescue.
- [ ] Internal operators can trace the outcome/status without seeing prohibited sensitive data.
