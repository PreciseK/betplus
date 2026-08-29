---
stepsCompleted: [1, 2, 3, 4]
status: 'complete'
completedAt: '2026-08-10'
epicCount: 10
storyCount: 88
coverage: 'all 28 requirement modules and all 34 UX design requirements cited in story acceptance criteria'
inputDocuments:
  - 'Betplus_PRD.md'
  - '_bmad-output/planning-artifacts/architecture.md'
  - 'UX-Design.md'
  - 'design.md'
  - 'project-context.md'
  - 'docs/index.md'
  - 'docs/project-overview.md'
  - 'docs/data-models.md'
  - 'docs/integration-architecture.md'
  - 'docs/development-guide.md'
  - 'docs/deployment-guide.md'
requirementIdScheme: 'REQ-<MODULE>-<NNN> (preserved from PRD — not renumbered)'
---

# Betplus - Epic Breakdown

## Overview

This document decomposes the Betplus PRD, UX Design Standard, Design System and Architecture
into implementable epics and stories.

**Requirement identifiers are preserved, not renumbered.** The PRD carries 414 requirements
under a `REQ-<MODULE>-<NNN>` scheme, and `project-context.md`, `architecture.md` and the
brownfield documentation in `docs/` all cite those identifiers. Renumbering them to `FR1..FRn`
would sever traceability across five documents to gain nothing. The sections below therefore
inventory requirements **by module with identifier ranges and scope**, and the coverage map
traces epics to those identifiers. The PRD remains the authoritative requirement text.

## Requirements Inventory

### Functional Requirements

Twenty modules, 268 identifiers.

| Module | IDs | Scope |
|---|---|---|
| `REQ-ARCH` | 001–003 | Single wallet writer; no engine writes ledger; Python engine holds no money state |
| `REQ-GEC` | 001–030 | Engine Contract: purity, statelessness, no engine randomness, `resolve`/`replay`/`describe`, registry, versioned prize tables, 95% RTP ceiling, publication gate, new-game admission |
| `REQ-RNG` | 001–009 | CSPRNG via Fairness Service, one seed per ticket, WORM hash-chained seed records, lab certification, chi-square monitoring, prohibited RNG functions in PHP and Python |
| `REQ-ID` | 001–027 | MSISDN identity in E.164, OTP verification, USSD gateway signature, OPay-assisted registration and name lookup, three KYC tiers, NIN precondition, vault isolation, age gate, sessions |
| `REQ-PAY` | 001–020 | OPay collections, Bank Account + OTP primary path, two signature schemes, static egress IP, idempotent collections, callback verification, status polling, Payment Orchestrator abstraction |
| `REQ-WAL` | 001–043 | Double-entry ledger, integer kobo, dual balance, named accounts, fund-flow rules, 1× turnover, append-only journal, no negative balances, idempotency keys, nightly reconciliation |
| `REQ-PO` | 001–012 | `OpayWalletNg` payouts, net-prize credit on settlement, status enumeration handling, manual review threshold, OPay-side review disabled, retry to manual queue, receipts, kobo/Naira unit handling |
| `REQ-FLOAT` | 001–008 | Float ledger account, scheduled polling, tiered alerting, cover policy with largest-prize headroom, top-up runbook, graceful degradation at halt, MDR accrual, days-of-cover display |
| `REQ-TAX` | 001–011 | Withholding, GGR levy and CIT; rates and bases as effective-dated configuration; per-state basis definition; residency from KYC; player-visible deduction; replayable computation |
| `REQ-GEO` | 001–011 | State attribution before ticket creation, multi-signal precedence, MSISDN prefix prohibited, licence footprint enforcement, fail-closed below confidence, spoofing detection, versioned rulesets |
| `REQ-NOT` | 001–008 | SMS guaranteed channel, versioned templates, mandatory transactional messages, marketing suppression for exclusions, delivery receipts, quiet hours, USSD mirrored to SMS |
| `REQ-RG` | 001–022 | 18+, cross-game limits, asymmetric limit changes, cool-off, self-exclusion, reality checks, net position, exclusion registry integration keyed to NIN, fail-closed, per-game velocity, no dark patterns |
| `REQ-BO` | 001–025 | RBAC, audit logging, maker-checker workflow, Player 360, ticket audit via engine replay, financial and per-state reporting, float and jurisdiction consoles, MFA, task-based navigation, tables and exports, masked identifiers |
| `REQ-TKT` | 001–012 | Ticket creation in eligibility and commitment phases, state machine, outcome at creation, no outcome leakage, auto-settle on abandonment, settlement, immutability, concurrency cap, disclosure, engine idempotency, in-transaction re-assertion |
| `REQ-BR` | 001–022 | BlackRed: 1–5 colour predictions, all-must-match, deterministic replay, certified multipliers (1.85× / 3.60× / 7.00× / 13.50× / 26.00×), published true odds, conduct rules, colour never sole carrier |
| `REQ-HG` | 001–074 | Heritage: 9-of-90 board, 5-of-9 selection, channel parity, outcome determination and consistent reveal, full board revealed at settlement, tier structure, second-chance draw partner bridge, tradition and leader as cosmetic, 90-item immutable catalogue, cultural sign-off gate, presentation rules |
| `REQ-DATA` | 001–004 | Seven-year retention, PII encryption, masked non-production data, data-subject tooling |
| `REQ-WEB` | 001–004 | Responsive mobile-first, FCP and payload budgets, WCAG, CSP/HSTS at Nginx |
| `REQ-APP` | 001–010 | Single React Native codebase, Hermes and size budget, native security modules, secure storage, OTA disabled, store requirements both platforms, offline degradation, location permission, animation performance |
| `REQ-USSD` | 001–021 | Finite state machine with Redis session, resumable sessions, funded ticket never lost, invalid input handling, in-session funding, account and responsible-gambling menus |
| `REQ-ANL` | 001–008 | Event schema, server-side emission for money and outcome, first-party analytics in the back office, retention and rollups, segmentation, paired commercial and harm metrics |

### NonFunctional Requirements

Six modules, 146 identifiers.

| Module | IDs | Scope |
|---|---|---|
| `REQ-HOST` | 001–011 | VPS/WHM with root, static egress IP, Nginx reverse proxy to FPM and Passenger, multi-process Python, supervised queue workers, cron for scheduled work only, explicit tuning, rehearsed scale path, off-server backups, mirrored staging, scripted atomic deploys |
| `REQ-NFR` | 001–017 | Latency, throughput tiers by topology, asset and binary budgets, animation frame rate, availability, RPO/RTO, graceful degradation, fail-closed availability parity, engine isolation, circuit breakers, scalability, observability, accessibility and localisation, scale-out trigger, per-service resource limits |
| `REQ-SEC` | 001–037 | Threat model, penetration testing, input validation, rate limiting, secret management, SAST/SCA, password hashing, mobile hardening, USSD gateway auth, NIN/BVN handling, engine isolation, fraud and insider controls, segregation of duties, NFIU reporting, infrastructure hardening |
| `REQ-COMP` | 001–056 | Licensing, location and remittance, three tax layers, AML programme and SCUML/goAML registration, data protection and NDPC, player protection, advertising standards |
| `REQ-QA` / `REQ-CERT` | QA 001–026, CERT 001–005 | Monte Carlo validation, RTP ceiling, determinism, engine purity, prohibited randomness, outcome leakage, ledger invariants, idempotency, double payout, cross-game isolation, cosmetic independence, USSD length, OPay units and signatures, float exhaustion, fail-closed, tax matrix, turnover, naming, exclusion enforcement, abandonment, catalogue mapping, spoofing, diacritics, deployment rollback; plus RNG, prize table, provider, security and registry certification |
| `REQ-REL` | 001–002 | Atomic deployment with rehearsed rollback; configuration released independently of code |

### Additional Requirements

From `architecture.md` — technical requirements that shape epic sequencing.

**Starter templates are specified.** Four workspaces are initialised independently, and this is
Epic 1, Story 1:

```
composer create-project laravel/laravel apps/platform
uv init apps/engine-heritage && uv add fastapi uvicorn gunicorn pydantic
npx create-expo-app@latest apps/mobile   (then expo prebuild)
npx create-next-app@latest apps/web --typescript --app --eslint   (then output: 'export')
```

- **Schema reconstruction is the true critical path.** The inherited migrations do not build a working database — `gameRound`, `dailyRevenueSummary`, `eventLog` and `vw_walletDualBalance` have no DDL. Until Laravel migrations can build a database from source control, no staging environment exists and no §17 release gate can execute.
- **Static egress IP must be provisioned and registered with OPay before any payment integration can be tested**, in both test and production, in both directions.
- **Credential rotation precedes version control.** Live secrets sit in the working tree with no root `.gitignore` and no git history yet; rotate, ignore, then initialise.
- **Database grants enforce the ledger-writer restriction.** Engines receive no database credential at all; the identity vault is a separate schema with its own credential.
- **Engine endpoints bind to loopback with mTLS.** Nginx client certificates, with a shared-secret fallback recorded as a deviation if cPanel makes mTLS impractical.
- **The Engine Contract conformance suite is a shared JSON fixture set** in `packages/engine-contract/`, executed against both engines by both test suites.
- **Two naming-convention mapping sites only** — the PHP API resource layer and the TypeScript API client.
- **Queue workers are supervised systemd units with liveness alerting**, never cron.
- **Warm standby requires both egress IPs registered with OPay in advance**, or payments cannot resume after failover.
- **Analytics is first-party**, landing in an append-only partitioned store surfaced through the back office; no third-party analytics platform receives player events.
- **Maker-checker is one workflow** in `Domain/Approvals/`, not nine bespoke approval paths.
- **Exports run asynchronously** behind signed expiring links so a wide date range cannot occupy a request worker.
- **Two version facts remain unverified** — Laravel 13's minimum PHP version and the current Next.js major — to be confirmed before initialisation.

### UX Design Requirements

From `UX-Design.md` and `design.md`. Each is scoped to generate at least one story with testable
acceptance criteria.

**Design system foundations**

- **UX-DR1**: Colour system — primitive tokens and semantic tokens, with defined distribution rules (`design.md` §4).
- **UX-DR2**: Typography — families and scale (`design.md` §5).
- **UX-DR3**: Spacing tokens and responsive layout structure (`design.md` §6).
- **UX-DR4**: Shape, elevation and iconography tokens (`design.md` §7).
- **UX-DR5**: Logo asset system and usage rules (`design.md` §3).
- **UX-DR6**: Motion and sound tokens, including reduced-motion equivalents (`design.md` §9).

**Component library** — every component ships default, hover, pressed, focus-visible, disabled, loading, success and error states as applicable.

- **UX-DR7**: Button — one dominant action per region, outcome-describing labels, never disabled without adjacent explanation, consequence statement on irreversible actions, 44×44 CSS px / 48×48 dp minimum.
- **UX-DR8**: Form controls — persistent labels, placeholders as examples only, `₦` outside the editable numeral, validation on blur or submit rather than per keystroke, OTP as one logical input supporting paste and autofill.
- **UX-DR9**: Money and balance components — BalanceCard distinguishing Play and Winnings Balance by title and explanation rather than colour; TransactionRow carrying type, game or provider, WAT timestamp, signed amount and status; WinReceipt carrying gross prize, withholding tax, net credited, destination and status, ticket reference and help route.
- **UX-DR10**: Feedback set — Toast (4–8s, pausable, never the sole record of a money event), Banner, InlineMessage, Dialog, FullPage, each with defined applicability.
- **UX-DR11**: Empty and loading states — skeletons only where final structure is known; hidden balance `••••`, loading, and unavailable are three visually distinct states.

**Flow and content patterns**

- **UX-DR12**: Ten system states designed for every critical flow — initial, loading, empty, pending, success, recoverable error, blocking error, offline, expired, partial dependency outage (`UX-Design.md` §9). No indefinite spinner; never optimistically show money as settled before server confirmation.
- **UX-DR13**: Commitment summary — action and amount, source and destination, game or provider, fees and tax or verified "No fee", expected timing, cancellation or irreversibility note, with the amount in the final CTA.
- **UX-DR14**: Receipt — stable reference, timestamp, amount breakdown, status history, and help deep-linked to the ticket or transaction without retyping sensitive data.
- **UX-DR15**: Currency formatting utility — `₦2,400` / `₦2,400.50`, never raw kobo, no abbreviation in commitments, receipts, limits or reports, decimal alignment and tabular numerals in tables.
- **UX-DR16**: Canonical terminology enforcement — Play Balance and Winnings Balance only; `PLAYER_PAYOUT`, "cash wallet" and game-specific wallet names prohibited in all player-facing copy.
- **UX-DR17**: Form validation pattern — format on blur, server rules after submit, focused error summary linking to each field, non-sensitive entries preserved after recoverable errors, and never revealing whether another person's NIN, phone or account exists.
- **UX-DR18**: Turnover progress display — plain-language explanation of transfer and turnover state, never an unexplained lock.

**Accessibility**

- **UX-DR19**: WCAG **2.2 AA** baseline across Web and App — complete keyboard flow with visible focus and no traps, correct names/roles/values and status announcements, landmarks and native controls, 44×44 CSS px / 48×48 dp targets, reflow at 320 CSS px, zoom to 400%, device text scaling without clipping, error summary plus inline errors, no timeout without warning or extension.
  > **Discrepancy:** the PRD specifies WCAG 2.1 AA at `REQ-NFR-050` and `REQ-WEB-003`; the UX Design Standard specifies 2.2 AA. 2.2 is the stricter and more current standard, and the UX document already carries its target-size requirement. Recorded as 2.2; the PRD requires alignment.
- **UX-DR20**: Text plus icon or pattern for Black/Red selections, game results and every status — colour is never the sole carrier of meaning.
- **UX-DR21**: Screen-reader game completion — one full BlackRed play and one full Heritage play, with Heritage's nine-position result navigable as a coherent grid or list after reveal.
- **UX-DR22**: Reduced-motion paths exposing the same result and sequence meaning as the animated path.

**Content and localisation**

- **UX-DR23**: Voice and content standard — clear, warm, direct, accountable; important fact first; verbs on buttons; "we" for system responsibility; restrained around wins, neutral around losses.
- **UX-DR24**: English and Nigerian Pidgin content, with correct diacritic rendering and layouts tolerating 40% text expansion.
- **UX-DR25**: Notification patterns — text beginning with state and amount rather than hype; push permission requested only after a relevant benefit is explained; transactional and marketing consent separate; unread badges for durable items only.

**Governance gates**

- **UX-DR26**: Ethical and responsible design gate — fourteen named release blockers enforced as a reviewable checklist (`UX-Design.md` §13).
- **UX-DR27**: Anti-AI-slop review — eleven questions answered with visible evidence before any UI work is accepted (`UX-Design.md` §17).
- **UX-DR28**: UX definition of done — thirteen-item checklist applied per feature (`UX-Design.md` §18).

**Back office**

- **UX-DR29**: Task-based navigation with role-based removal of inaccessible destinations.
- **UX-DR30**: Table pattern — scoped default date ranges, sticky sortable headers, filters with visible active state and URL persistence, right-aligned financial values, label-plus-colour status, bulk actions showing count and requiring scoped confirmation, and visually distinct empty, loading, partial, stale and error states.
- **UX-DR31**: Maker-checker interface — maker sees before/after diff and supplies justification; checker sees risk context, affected states and games, effective time and validation results; self-approval blocked; rejection requires a reason and preserves the draft.
- **UX-DR32**: Player 360 as a single task-oriented page with identifiers masked by default and every action showing its permission, reason and audit consequence.
- **UX-DR33**: Operational dashboards leading with actionable exception queues; float console leading with balance, days of cover, pending payout value, threshold state and next action; compliance console leading with licence expiry, registry freshness, failed checks and per-state obligations; every chart carrying a text summary, accessible legend, precise tooltips and an underlying table.
- **UX-DR34**: USSD equivalence — the same information architecture expressed within 160 characters per screen, in every locale.

### FR Coverage Map

Every requirement module and every UX design requirement maps to exactly one owning epic.
Where a module spans epics, the split is stated explicitly.

| Requirements | Epic | Coverage |
|---|---|---|
| `REQ-ARCH-001..003` | 1 | Single ledger writer established with the wallet schema and database grants |
| `REQ-HOST-001..011` | 1 | Server, static egress IP, Nginx, FPM, Redis, supervised workers, staging, atomic deploys |
| `REQ-ID-001..027` | 1 | MSISDN identity, OTP, OPay name lookup, KYC tiers, NIN, vault, sessions |
| `REQ-DATA-001..004` | 1 | Retention, encryption, masked non-production data, subject-rights tooling |
| `REQ-SEC-001..010`, `030..037` | 1 | Application and infrastructure hardening, secrets, vault isolation |
| `REQ-SEC-011` | 3 | Engine endpoint isolation — lands with the first engine |
| `REQ-SEC-020..025` | 6 | Fraud, insider risk, segregation of duties, NFIU reporting |
| `REQ-WEB-001..004` | 1 | Responsive shell, budgets, accessibility baseline, security headers |
| `REQ-REL-001..002` | 1 | Atomic release with rollback; configuration released independently of code |
| `REQ-WAL-001..029`, `034..043` | 2 | Double-entry ledger, dual balance, fund flow, integrity, reconciliation |
| `REQ-WAL-030..033` | 4 | 1× turnover — released with the withdrawal path that consumes it |
| `REQ-PAY-001..020` | 2 | OPay collections, signature schemes, idempotency, callbacks, orchestrator |
| `REQ-NOT-001..008` | 2 | Templates, transactional messages, delivery receipts, quiet hours |
| `REQ-GEC-001..030` | 3 | Engine Contract, registry, prize tables, RTP ceiling, publication gate |
| `REQ-RNG-001..009` | 3 | Fairness Service, seed chain, certification, prohibited functions |
| `REQ-BR-001..022` | 3 | BlackRed engine, certified multipliers, published odds, conduct |
| `REQ-TKT-001..012` | 3 | Two-phase ticket creation, states, no leakage, auto-settle, immutability |
| `REQ-GEO-001..011` | 3 | State attribution, licence footprint, fail-closed, spoofing detection |
| `REQ-TAX-001..011` | 3 | Withholding, GGR, CIT, per-state rulesets, replayable computation |
| `REQ-PO-001..012` | 4 | Payout engine, status handling, manual review, receipts, unit handling |
| `REQ-FLOAT-001..008` | 4 | Float account, polling, tiered alerts, cover policy, graceful degradation |
| `REQ-RG-001..022` | 5 | Limits, cool-off, self-exclusion, reality checks, registries, velocity, conduct |
| `REQ-COMP-050..056` | 5 | Player protection obligations |
| `REQ-COMP-001..046` | 10 | Licensing, remittance, tax, AML, data protection |
| `REQ-BO-001..025` | 6 | RBAC, audit, maker-checker, Player 360, consoles, tables, exports |
| `REQ-ANL-001..008` | 6 | First-party analytics landing in the back office |
| `REQ-HG-001..074` | 7 | Heritage engine, board, reveal, catalogue, draw partner, presentation |
| `REQ-USSD-001..021` | 8 | Finite state machine, session resumption, in-session funding, menus |
| `REQ-APP-001..010` | 9 | React Native app, native security modules, store requirements |
| `REQ-NFR-012..013` | 9 | App download size and animation frame rate |
| `REQ-CERT-001..005` | 10 | RNG, prize table, provider, security and registry certification |
| `REQ-NFR-001..011`, `014..017` | 10 | Latency, throughput tiers, availability, scale trigger, resource limits — verified at launch readiness, designed throughout |

**Quality gates by epic.** `REQ-QA-004,005,020,026` → Epic 1 · `REQ-QA-007,008,014,015,019` →
Epic 2 · `REQ-QA-001,002,003,006,017,018` → Epic 3 · `REQ-QA-009,016` → Epic 4 · `REQ-QA-021` →
Epic 5 · `REQ-QA-010,011,012,023,025` → Epic 7 · `REQ-QA-013` → Epic 8 · `REQ-QA-022,024` →
Epic 10.

**UX design requirements by epic.** UX-DR1–11, 17, 19, 23, 24 → Epic 1 (design system,
components, accessibility baseline, content standard) · UX-DR9, 12–16, 18 → Epic 2 (money
components, system states, commitment summary, currency formatting, terminology, turnover
display) · UX-DR20, 22 → Epic 3 (colour never sole carrier, reduced motion) · UX-DR14 → Epic 4
(receipts) · UX-DR26 → Epic 5 (ethical design gate) · UX-DR29–33 → Epic 6 (back office
patterns) · UX-DR21 → Epic 7 (screen-reader game completion) · UX-DR34 → Epic 8 (USSD
equivalence) · UX-DR27, 28 → Epic 10 (anti-slop review, definition of done).

## Epic List

### Epic 1: Platform Foundation and Verified Identity

A person can register with their Nigerian phone number, have their OPay wallet validated and
their name confirmed rather than typed, verify their NIN, reach Tier 1 and sign in on the web —
on a repository where the database builds from migrations, CI gates run, and staging mirrors
production.

**Requirements covered:** `REQ-ARCH-001..003` · `REQ-HOST-001..011` · `REQ-ID-001..027` ·
`REQ-DATA-001..004` · `REQ-SEC-001..010, 030..037` · `REQ-WEB-001..004` · `REQ-REL-001..002` ·
`REQ-QA-004, 005, 020, 026` · UX-DR1–11, 17, 19, 23, 24

### Epic 2: Money In — Wallet, Ledger and OPay Funding

A verified player can fund their Play Balance from their OPay wallet and see an accurate,
auditable balance and transaction history, with every movement double-entry booked and
reconciled nightly.

**Requirements covered:** `REQ-WAL-001..029, 034..043` · `REQ-PAY-001..020` ·
`REQ-NOT-001..008` · `REQ-QA-007, 008, 014, 015, 019` · UX-DR9, 12–16, 18

### Epic 3: First Game — BlackRed End to End

A player in a licensed state can stake on BlackRed, receive a provably fair outcome from a
certified prize table, and have winnings credited net of withholding tax.

**Requirements covered:** `REQ-GEC-001..030` · `REQ-RNG-001..009` · `REQ-BR-001..022` ·
`REQ-TKT-001..012` · `REQ-GEO-001..011` · `REQ-TAX-001..011` · `REQ-SEC-011` ·
`REQ-QA-001, 002, 003, 006, 017, 018` · UX-DR20, 22

### Epic 4: Money Out — Payout, Float and Withdrawal

A player can withdraw winnings to their OPay wallet and receive a receipt showing gross, tax and
net; Finance can see float cover and act before it runs out.

**Requirements covered:** `REQ-PO-001..012` · `REQ-FLOAT-001..008` · `REQ-WAL-030..033` ·
`REQ-QA-009, 016` · UX-DR14

### Epic 5: Responsible Gambling and Exclusion Registry

A player can set limits, take a break or self-exclude across every game and channel, and a
person on a state exclusion registry cannot play — while still being able to withdraw.

**Requirements covered:** `REQ-RG-001..022` · `REQ-COMP-050..056` · `REQ-QA-021` · UX-DR26

> **Ordering note.** Epic 5 is built after play exists because it gates play. It is nonetheless
> a licence condition and **must be complete before any real-money beta**, not deferred to
> Epic 10.

### Epic 6: Back Office and Operations

Operators can resolve a player's problem on one call, approve sensitive changes under
maker-checker, reconcile money, watch float and licences, publish prize tables, and read
first-party analytics.

**Requirements covered:** `REQ-BO-001..025` · `REQ-ANL-001..008` · `REQ-SEC-020..025` ·
UX-DR29–33

### Epic 7: Heritage — Second Game on the Same Contract

A player can dress a King or Queen in traditional regalia, see the full board at settlement, and
earn automatic entry into a 5/90 draw — proving that a second engine, in a second language, runs
on the same contract.

**Requirements covered:** `REQ-HG-001..074` · `REQ-QA-010, 011, 012, 023, 025` · UX-DR21

### Epic 8: USSD Channel

A player on a feature phone can register, fund, play both games and reach responsible-gambling
tools — with identical odds to web, inside 160 characters a screen.

**Requirements covered:** `REQ-USSD-001..021` · `REQ-QA-013` · UX-DR34

### Epic 9: Mobile App

Android and iOS players get a native app from one React Native codebase, hardened with
certificate pinning, root and jailbreak detection, mock-location detection and secure storage.

**Requirements covered:** `REQ-APP-001..010` · `REQ-NFR-012, 013` · `REQ-SEC-008`

### Epic 10: Certification, Compliance and Launch Readiness

The platform can be licensed, certified and launched in one state, with every §17.4 release gate
passing and every regulator filing made.

**Requirements covered:** `REQ-CERT-001..005` · `REQ-COMP-001..046` · `REQ-QA-022, 024` ·
`REQ-NFR-001..011, 014..017` · UX-DR27, 28

### Dependency map

| Epic | Requires | Standalone value delivered |
|---|---|---|
| 1 | — | Real, demonstrable registration and sign-in |
| 2 | 1 | Money in, ledger balances, reconciliation runs |
| 3 | 1, 2 | A complete, playable, certified game — buildable and testable in staging; **real-money play in any state additionally requires Epic 5** (see Story 3.6) |
| 4 | 2 | Money out, float managed |
| 5 | 1 | Licence-condition capability, independent of which games exist. **Gates real-money launch** |
| 6 | 1–4 for data | Operators can work |
| 7 | 1–3 | A second game, and the real test of the Engine Contract |
| 8 | 1–5 | An entire customer segment (persona P2) |
| 9 | 1–5 | An entire delivery channel |
| 10 | all | Launch |

No epic requires a later epic to function.

**Shared-file note.** Epics 2 and 4 both target `Domain/Wallet/`. This is meaningful rather than
incidental overlap — Epic 2 builds the ledger, Epic 4 adds turnover release and payout booking.
They remain separate because withdrawal is a distinct user outcome and a merged epic would
exceed what a single implementation context can hold. Epic 4's stories are ordered to extend
Epic 2's work rather than revise it.

---

## Epic 1: Platform Foundation and Verified Identity

A person can register with their Nigerian phone number, have their OPay wallet validated and
their name confirmed rather than typed, verify their NIN, reach Tier 1 and sign in on the web —
on a repository where the database builds from migrations, CI gates run, and staging mirrors
production.

### Story 1.1: Initialise the monorepo and its four workspaces

As a platform engineer,
I want the repository scaffolded with all four workspaces and their tooling,
So that every subsequent story has a place to put code and a command to run it.

**Acceptance Criteria:**

**Given** an empty repository
**When** the four initialisation commands from the architecture are run
**Then** `apps/platform` (Laravel), `apps/engine-heritage` (FastAPI), `apps/mobile` (Expo) and `apps/web` (Next.js with `output: 'export'`) exist and each starts locally
**And** `apps/engine-blackred`, `apps/ussd`, `packages/`, `infra/`, `tools/` and `docs/` exist per the architecture tree
**And** `project-context.md` sits at the repository root
**And** versions are pinned as verified on 10 August 2026: **PHP 8.4** (`REQ-HOST-012`), Laravel 13.24, Filament v5.7, Next.js 16.3 on Node 20.9+, FastAPI 0.141 on Python 3.12, Expo SDK 57
**And** the Heritage engine uses Uvicorn's multi-process worker manager, not Gunicorn with the deprecated `uvicorn.workers.UvicornWorker`
**And** before the interpreter upgrade, the host is checked for Imunify360 Hardened PHP, which replaces cPanel's PHP packages and can break EasyApache installs of new versions

**Given** the workspaces exist
**When** a developer runs the root task runner
**Then** every workspace's install, lint, test and build command is reachable from one place

### Story 1.2: Rotate exposed credentials and place the repository under version control

As a compliance officer,
I want every credential in the working tree rotated before any history exists,
So that a secret is never committed and never needs to be scrubbed from a git history.

**Acceptance Criteria:**

**Given** the inherited working tree contains live values for `DB_PASS`, `APP_SECRET`, the mobile-money client and secret keys, the callback shared secret and the SMS credentials
**When** this story completes
**Then** every one of those credentials has been rotated at its provider and the old values are invalid
**And** a root `.gitignore` excludes `.env`, `ussd/config.php`, `vendor/`, `node_modules/`, `logs/`, `*.zip`, `error_log` and `.DS_Store`
**And** version control is initialised only after the rotation and the ignore file are both in place
**And** secret scanning runs in CI and fails the build on a detected credential (`REQ-SEC-005`)

### Story 1.3: Reconstruct the schema as migrations and stand up staging

As a platform engineer,
I want the entire database to build from migrations in source control,
So that staging exists and every release gate in §17 has somewhere to run.

**Acceptance Criteria:**

**Given** the inherited production schema is undocumented in source control
**When** migration discipline is established
**Then** Laravel migrations are the sole schema authority, and a CI check fails the build if the live schema diverges from a fresh `migrate` (`REQ-DATA-001`)
**And** a fresh `migrate` on an empty database produces a schema the application runs against with no manual SQL
**And** the reconstruction is scoped to what Epic 1 needs — identity, session, audit and configuration tables — with wallet, ticket, tax and game tables created by the stories that introduce them
**And** tables carrying no code references are excluded rather than carried forward

> **Partitioning note.** `ticket`, `ledgerEntry` and `taxDeduction` require monthly range
> partitions and, for the ledger, a `stateCode` index (`REQ-NFR-032`). These are defined in
> Stories 2.1 and 3.6 as those tables are created, because partitions are free to define on an
> empty table and expensive to retrofit onto a populated one.

**Given** migrations build the schema
**When** staging is provisioned
**Then** it mirrors production's cPanel version, PHP and Python versions and Nginx configuration (`REQ-HOST-010`)
**And** it contains only masked or synthetic data (`REQ-DATA-003`)

### Story 1.4: Provision the server, static egress IP and reverse-proxy topology

As a platform engineer,
I want the production topology standing with a static egress IP registered at OPay,
So that payment integration can be tested at all.

**Acceptance Criteria:**

**Given** a VPS or dedicated server with root and WHM access (`REQ-HOST-001`)
**When** the topology is configured
**Then** Nginx terminates TLS and reverse-proxies to PHP-FPM and to the Python application server, and engine endpoints bind to loopback only (`REQ-HOST-003`)
**And** Redis runs with AOF persistence, and MariaDB and Redis are not reachable from any public interface (`REQ-SEC-030`)
**And** PHP-FPM pool sizing, opcache, InnoDB buffer pool and Redis `maxmemory` are explicitly tuned and documented (`REQ-HOST-007`)
**And** per-service resource limits are set so reporting and analytics cannot starve the play path (`REQ-NFR-017`)

**Given** the server is provisioned
**When** the egress IP is established
**Then** it is static, registered on the OPay dashboard for both test and production, and placed under change control (`REQ-HOST-002`, `REQ-PAY-011`)
**And** OPay's inbound IPs are allowlisted

### Story 1.5: Establish the CI gate and deployment pipeline

As a platform engineer,
I want a merge gate that enforces the rules no reviewer can reliably catch,
So that a prohibited function or an unbalanced ledger cannot reach production.

**Acceptance Criteria:**

**Given** CI is configured
**When** a pull request is opened
**Then** PHPStan, Pest, pytest, TypeScript compilation and secret scanning all run and block on failure
**And** custom PHPStan rules fail any write to `ledgerEntry`, `wallet` or `walletTransaction` from outside the Wallet Service (`REQ-ARCH-002`)
**And** a static check fails the build on `rand`, `mt_rand`, `shuffle`, `array_rand` or `str_shuffle` in PHP, or the `random` module in the Heritage engine (`REQ-QA-005`)
**And** an engine-purity check fails the build on database access, outbound network calls or filesystem writes inside either engine (`REQ-QA-004`)

**Given** a release is approved
**When** it deploys
**Then** it ships as an atomic symlinked release directory with the previous release retained (`REQ-HOST-011`, `REQ-REL-001`)
**And** rollback is exercised on staging with no in-flight ticket lost and no queue worker orphaned (`REQ-QA-026`)

### Story 1.6: Implement the design token set and core component library

As a frontend engineer,
I want tokens and components that already encode the design system's rules,
So that every screen built afterwards inherits them rather than reinventing them.

**Acceptance Criteria:**

**Given** the Design System defines colour primitives and semantic tokens, typography, spacing, shape, elevation, iconography and motion
**When** the token set is implemented
**Then** all six token groups exist as code and no component hardcodes a palette, type, spacing or motion value — colour (UX-DR1), typography (UX-DR2), spacing and responsive structure (UX-DR3), shape, elevation and iconography (UX-DR4), logo assets and usage (UX-DR5), motion and sound (UX-DR6)
**And** reduced-motion equivalents are defined for every motion token (UX-DR6, UX-DR22)

**Given** the token set exists
**When** the core components are built
**Then** Button (UX-DR7), form controls (UX-DR8), money and balance components (UX-DR9), the feedback set of toast, banner, inline, dialog and full page (UX-DR10), and empty and loading states (UX-DR11) each ship with default, hover, pressed, focus-visible, disabled, loading, success and error states as applicable
**And** form controls use persistent visible labels with placeholders as examples only, place `₦` outside the editable numeral, validate on blur or submit rather than per keystroke, and treat OTP as one logical input supporting paste and autofill (UX-DR8)
**And** every interactive target meets 44×44 CSS px on web and 48×48 dp on native
**And** a hidden balance, a loading balance and an unavailable balance render as three visually distinct states (UX-DR11)
**And** no component conveys meaning by colour alone (UX-DR20)

### Story 1.7: Register with a Nigerian phone number

As a prospective player,
I want to start an account with my phone number and prove it is mine,
So that I can begin using Betplus.

**Acceptance Criteria:**

**Given** a person enters a Nigerian mobile number in any accepted format
**When** it is submitted
**Then** it normalises to E.164 (`REQ-ID-001`) and a 6-digit OTP is sent with a 5-minute TTL
**And** at most 5 attempts and 3 resends per hour are permitted, with exponential backoff (`REQ-ID-003`)

**Given** an MSISDN already holds an account
**When** registration is attempted
**Then** the response does not reveal whether that account exists (UX-DR17)
**And** the person is routed to sign-in

**Given** the OTP is entered correctly
**When** verification succeeds
**Then** exactly one account exists for that MSISDN (`REQ-ID-002`) and the person proceeds to identity confirmation

### Story 1.8: Confirm identity from the OPay wallet

As a prospective player,
I want my name confirmed from my OPay wallet rather than typed,
So that registration is fast and my payout name always matches.

**Acceptance Criteria:**

**Given** a verified MSISDN
**When** registration calls OPay wallet validation
**Then** the returned first and last name populate `registeredName` and are presented for confirmation (`REQ-ID-010`, `REQ-ID-011`)
**And** that name is retained as authoritative for payout matching and cannot be overridden by player input (`REQ-ID-013`)

**Given** the MSISDN has no OPay wallet
**When** validation returns that result
**Then** the person is told plainly that a Betplus account requires an OPay wallet and is given the route to open one (`REQ-ID-012`)
**And** the refusal is recorded so its rate can be measured

**Given** OPay is unreachable
**When** validation cannot complete
**Then** the person sees a recoverable error preserving their progress, not a failure (UX-DR12)

### Story 1.9: Verify NIN and reach Tier 1

As a prospective player,
I want to verify my NIN and date of birth,
So that I can play, and so that self-exclusion protections work.

**Acceptance Criteria:**

**Given** a Tier 0 account
**When** date of birth and NIN are submitted
**Then** age is confirmed as 18 or over, and an under-18 result blocks play and deposit and triggers the reviewed refund and reporting path (`REQ-ID-024`)
**And** the NIN is verified through the contracted identity vendor (`REQ-ID-022`)

**Given** NIN verification succeeds
**When** the tier is upgraded
**Then** the account reaches Tier 1 with a ₦200,000 monthly deposit limit and play permitted (`REQ-ID-020`)

**Given** NIN verification has not completed
**When** the player attempts to play
**Then** play is refused with `KYC_TIER_REQUIRED` and an explanation of why NIN is required (`REQ-ID-021`)
**And** the drop-off is instrumented against M14

### Story 1.10: Isolate NIN and BVN in the identity vault

As a compliance officer,
I want national identifiers held under separate access control,
So that a compromise of the application does not expose them.

**Acceptance Criteria:**

**Given** a verified NIN or BVN
**When** it is stored
**Then** it is written to a separate database schema with its own credential, reachable only by the identity service (`REQ-ID-023`)
**And** application services receive a token or hash, never the raw value
**And** every access is individually audited

**Given** any log, analytics event, API response or non-production environment
**When** it is inspected
**Then** no raw NIN or BVN appears at any level (`REQ-NFR-041`, `REQ-SEC-010`, `REQ-ANL-003`)

### Story 1.11: Sign in and hold a session

As a registered player,
I want to sign in and stay signed in safely,
So that I can return without friction and without risk.

**Acceptance Criteria:**

**Given** valid credentials
**When** sign-in succeeds
**Then** an access token with a TTL of 30 minutes or less is issued, with a refresh token of 30 days or less using rotation and reuse detection (`REQ-ID-026`)
**And** web sessions use `HttpOnly`, `Secure`, `SameSite=Lax` cookies
**And** session state is held in Redis so a second application server would inherit it

**Given** a refresh token is presented twice
**When** reuse is detected
**Then** the token family is revoked and the player is required to sign in again

### Story 1.12: Meet the accessibility and content baseline on the web shell

As a player using assistive technology,
I want the site to be fully operable,
So that I can use Betplus on equal terms.

**Acceptance Criteria:**

**Given** the web shell
**When** it is built
**Then** it is responsive and mobile-first, with the two-column desktop layout as the adaptation rather than the baseline (`REQ-WEB-001`)
**And** first contentful paint on 3G is 2.5 s or less and the first-play asset payload is 2.5 MB or less (`REQ-WEB-002`)
**And** CSP, HSTS and `X-Frame-Options` are set at Nginx (`REQ-WEB-004`)

**Given** any screen in the web shell
**When** it is audited
**Then** it meets WCAG 2.2 AA: complete keyboard flow with visible focus and no traps, correct names, roles, values and status announcements, landmarks and native controls (`REQ-WEB-003`, UX-DR19)
**And** it reflows at 320 CSS px, tolerates 400% zoom and device text scaling without clipping
**And** errors present as a focused summary plus inline messages linked to their fields (UX-DR17)

> **Standard note.** WCAG **2.2 AA** throughout. The PRD (`REQ-WEB-003`, `REQ-NFR-050`) and the
> UX Design Standard §12 are aligned on this.

**Given** any player-facing copy
**When** it is reviewed
**Then** it follows the voice standard, uses canonical balance terms, and exists in English and Nigerian Pidgin with correct diacritics and layouts tolerating 40% text expansion (UX-DR16, UX-DR23, UX-DR24)

---

## Epic 2: Money In — Wallet, Ledger and OPay Funding

A verified player can fund their Play Balance from their OPay wallet and see an accurate,
auditable balance and transaction history, with every movement double-entry booked and reconciled
nightly.

### Story 2.1: Establish the double-entry ledger and account structure

As a finance operator,
I want every movement of money recorded as balanced double entry,
So that the books can always be proven correct.

**Acceptance Criteria:**

**Given** the wallet and ledger tables are created by this story
**When** the migration runs
**Then** monthly range partitions are defined on `ledgerEntry` at creation, with an index on `stateCode` for per-state remittance queries (`REQ-NFR-032`)

**Given** the ledger schema
**When** any monetary transaction is written
**Then** it produces balanced debit and credit `ledgerEntry` rows whose sum is exactly zero, and a non-zero imbalance raises a P0 (`REQ-WAL-001`)
**And** all amounts are integer kobo in `BIGINT` columns named for kobo, with currency `NGN` explicit (`REQ-WAL-003`)
**And** named accounts exist for `PLAYER_PLAY`, `PLAYER_WINNINGS`, `SUSPENSE`, `HOUSE_REVENUE`, `PAYMENT_CLEARING:OPAY`, `OPAY_FLOAT`, `PRIZE_LIABILITY`, `WHT_PAYABLE:{state}`, `GGR_LEVY_PAYABLE:{state}`, `FEES` and `DRAW_TICKET_COST` (`REQ-WAL-012`)

**Given** the journal exists
**When** an `UPDATE` or `DELETE` is attempted against `ledgerEntry`
**Then** the database refuses it through permissions and triggers, not application convention (`REQ-WAL-040`)
**And** a negative balance is impossible at database level (`REQ-WAL-041`)
**And** only the Wallet Service credential may write ledger tables (`REQ-ARCH-001`, `REQ-ARCH-002`)

### Story 2.2: Hold two balances per player

As a player,
I want my deposits and my winnings held separately and labelled clearly,
So that I understand what I can stake and what I can withdraw.

**Acceptance Criteria:**

**Given** a registered player
**When** their wallet is provisioned
**Then** exactly one Play Balance and one Winnings Balance exist, platform-level rather than per game (`REQ-WAL-010`, `REQ-WAL-011`)
**And** balances are ledger-derived with a transactionally cached column under optimistic concurrency (`REQ-WAL-002`)

**Given** either balance is displayed
**When** the player views it
**Then** it is labelled "Play Balance" or "Winnings Balance" and distinguished by title and explanation rather than colour (UX-DR9, UX-DR16)
**And** no player-facing surface uses `PLAYER_PAYOUT`, "cash wallet" or a game-specific wallet name

### Story 2.3: Fund the Play Balance from an OPay wallet

As a player,
I want to add money from my OPay wallet,
So that I can play.

**Acceptance Criteria:**

**Given** a Tier 1 player
**When** they enter an amount and confirm
**Then** a collection is created with `payMethod: BankAccount`, the account number derived from their registered MSISDN and OPay's constant bank code (`REQ-PAY-002`)
**And** a commitment summary shows action, amount, source, destination, fees or verified "No fee", expected timing and irreversibility, with the amount in the final CTA (UX-DR13)

**Given** the collection is created
**When** OPay requires an OTP
**Then** the OTP is submitted server-side so the flow completes without leaving Betplus (`REQ-PAY-001`)

**Given** the collection succeeds
**When** it is confirmed
**Then** the deposit credits Play Balance only, never Winnings Balance (`REQ-WAL-020` rule 1)
**And** the player receives an SMS confirmation whose text begins with the state and amount rather than hype (`REQ-NOT-003`, UX-DR25)
**And** the money is never shown as settled before server confirmation (UX-DR12)

**Given** notification preferences
**When** they are configured
**Then** transactional and marketing consent are recorded separately, push permission is requested only after a relevant benefit is explained, quiet hours apply to marketing but never to money or ticket receipts, and unread badges represent durable items only (`REQ-NOT-004`, `REQ-NOT-006`, UX-DR25)

### Story 2.4: Handle collection callbacks, replays and timeouts safely

As a finance operator,
I want provider callbacks to be verified and idempotent,
So that a retry can never double-credit a player.

**Acceptance Criteria:**

**Given** a callback arrives
**When** it is processed
**Then** its signature and source IP are verified, and an unverifiable callback is logged and rejected (`REQ-PAY-014`)
**And** collections are idempotent on the Betplus reference, so a replay produces no duplicate ledger entry (`REQ-PAY-013`)
**And** OPay's "payment reference already exists" errors are treated as success-with-existing-order, not failure

**Given** no callback arrives within the timeout
**When** 90 seconds elapse
**Then** the orchestrator polls status with exponential backoff for up to 24 hours before marking `UNKNOWN` and escalating — a payment is never assumed failed on timeout alone (`REQ-PAY-015`)

**Given** the same endpoint is called 100 times concurrently with one idempotency key
**When** the calls complete
**Then** exactly one effect occurred (`REQ-QA-008`)

### Story 2.5: Separate the two OPay signature schemes

As a platform engineer,
I want collections and payouts signed by distinct, non-interchangeable signers,
So that the two schemes cannot be confused.

**Acceptance Criteria:**

**Given** the OPay integration
**When** it signs a request
**Then** collections use HMAC-SHA512 with the merchant secret and payouts use RSA-SHA256 with the merchant key pair, through separate signer classes with separate configuration (`REQ-PAY-010`)
**And** RSA private keys exist only in the secret store, never in source control (`REQ-PAY-012`)

**Given** a test deliberately signs a collection with the payout signer or the reverse
**When** it runs
**Then** it fails loudly rather than silently (`REQ-QA-015`)

### Story 2.6: Show a transaction history and receipts

As a player,
I want a clear record of every money movement,
So that I can check what happened and get help if it went wrong.

**Acceptance Criteria:**

**Given** a player with transaction history
**When** they open it
**Then** each row shows type, game or provider, WAT timestamp, signed amount and status (UX-DR9)
**And** amounts render as `₦2,400`, never raw kobo, never abbreviated, decimal-aligned with tabular numerals (UX-DR15)

**Given** any money event
**When** the player opens its receipt
**Then** it carries a stable reference, timestamp, amount breakdown, status history and a help route deep-linked to that transaction without retyping sensitive data (UX-DR14)

**Given** a pending money state
**When** the player returns later
**Then** it persists in the history with a durable status, timestamp and expectation — not communicated by toast alone (UX-DR10, UX-DR12)

### Story 2.7: Reconcile the ledger nightly

As a finance operator,
I want every account reconciled every night,
So that a discrepancy is found in hours rather than at audit.

**Acceptance Criteria:**

**Given** a day's activity
**When** nightly reconciliation runs
**Then** cached balances are compared against the summed ledger for every active wallet, clearing accounts against OPay settlement files, and tax payable accounts against remittances made (`REQ-WAL-043`)
**And** any discrepancy raises P1 and escalates to Finance the same day
**And** the target of zero unreconciled records at T+1 is measurable (M11)

**Given** failure injection during a simulated scenario
**When** invariants are asserted
**Then** every journal balances, no balance is negative, and every tax deduction has a matching state-attributed payable entry (`REQ-QA-007`)

---

## Epic 3: First Game — BlackRed End to End

A player in a licensed state can stake on BlackRed, receive a provably fair outcome from a
certified prize table, and have winnings credited net of withholding tax.

### Story 3.1: Define the Engine Contract and its conformance suite

As a platform engineer,
I want one contract that every game engine implements and one fixture set that proves it,
So that adding a third game is an adapter plus configuration.

**Acceptance Criteria:**

**Given** the contract
**When** it is defined
**Then** `packages/engine-contract/` holds request and response JSON Schema plus `(seed, input) → expected output` fixtures per game (`REQ-GEC-001`)
**And** `resolve`, `replay` and `describe` are specified, with `replay` returning a byte-identical response to `resolve` for the same inputs

**Given** either engine
**When** the conformance suite runs against it
**Then** identical inputs produce identical outputs across restarts, hosts and deployments of the same engine version (`REQ-QA-003`)
**And** the same fixture set is executed by both the PHP and Python test suites

### Story 3.2: Issue seeds from the Fairness Service

As a compliance officer,
I want all randomness issued centrally and recorded tamper-evidently,
So that any historical outcome can be proven.

**Acceptance Criteria:**

**Given** a ticket is being created
**When** a seed is required
**Then** the Fairness Service issues exactly one seed per ticket from a CSPRNG, and no engine generates entropy (`REQ-RNG-001`, `REQ-RNG-002`)
**And** the ticket persists `rngSeedRef`, `rngAlgorithm` and `engineVersion`, sufficient to reconstruct the outcome exactly (`REQ-RNG-003`)

**Given** a seed record is written
**When** it is stored
**Then** it lands in an append-only store where each record includes the hash of its predecessor, so tampering is detectable (`REQ-RNG-004`)

### Story 3.3: Build the BlackRed engine as a pure function

As a platform engineer,
I want the game engine to compute outcomes and nothing else,
So that it can be certified, replayed and reasoned about.

**Acceptance Criteria:**

**Given** a stake, a seed and 1–5 colour predictions
**When** the engine resolves
**Then** it draws a result sequence of equal length from the seed and compares position by position, requiring every position to match for a win with no partial prizes (`REQ-BR-002`, `REQ-BR-003`)
**And** it holds no state, reads no database, calls no external service and writes no file (`REQ-GEC-002`)
**And** it runs as a standalone service with no database credential, bound to loopback (`REQ-SEC-011`)

**Given** the same `(seed, ticketId, input)`
**When** `replay` is called after a restart or redeployment
**Then** the output is byte-identical (`REQ-GEC-001`)

### Story 3.4: Publish the certified prize table through the publication gate

As a compliance officer,
I want prize tables to be versioned configuration that cannot be published above the ceiling,
So that the RTP ceiling is an enforced control rather than a policy.

**Acceptance Criteria:**

**Given** the BlackRed prize table
**When** it is configured
**Then** it holds the certified multipliers — 1.85×, 3.60×, 7.00×, 13.50×, 26.00× — as versioned, effective-dated configuration rather than code (`REQ-BR-011`, `REQ-GEC-020`)
**And** every ticket records the `prizeTableVersion` in force at creation and settles under that version permanently (`REQ-GEC-021`)

**Given** a table is submitted for publication
**When** the gate evaluates it
**Then** it is refused unless tier probabilities sum to exactly 1.0000, every tier's modelled RTP is at or below 95% gross and net of withholding, an actuarial certification reference is recorded, and maker-checker approval is present (`REQ-GEC-022`, `REQ-GEC-023`, `REQ-GEC-025`)
**And** a Monte Carlo run of at least 10,000,000 tickets matches the modelled tier frequencies and RTP within tolerance (`REQ-QA-001`, `REQ-QA-002`)

### Story 3.5: Attribute every ticket to a state, failing closed

As a compliance officer,
I want every ticket to carry a proven state of play,
So that licence and tax obligations are correct and provable.

**Acceptance Criteria:**

**Given** a ticket is being created
**When** attribution runs
**Then** a `stateCode` and `attributionConfidence` are resolved before creation and persisted immutably (`REQ-GEO-001`)
**And** signals combine by documented precedence, and MSISDN prefix is never used (`REQ-GEO-002`, `REQ-GEO-003`)

**Given** confidence falls below 0.80
**When** the ticket is requested
**Then** creation is refused with `LOCATION_UNVERIFIED` rather than defaulting to a state, and no money moves (`REQ-GEO-005`)

**Given** the attributed state is outside the active licence footprint
**When** the ticket is requested
**Then** creation is refused with `STATE_NOT_LICENSED` (`REQ-GEO-004`)

**Given** VPN, proxy or mock location is detected
**When** the ticket is requested
**Then** play is blocked and the account is flagged (`REQ-GEO-006`, `REQ-QA-024`)

**Given** a state is proposed for the active licence footprint
**When** the jurisdiction ruleset is applied
**Then** it is refused unless an exclusion registry is configured for that state (`REQ-RG-010`, `REQ-COMP-051`)
**And** attribution drives licence check, prize table resolution, withholding rate and basis, GGR attribution, reporting bucket and registry selection (`REQ-GEO-007`)
**And** attribution logic is versioned and recorded per ticket so a historical ticket is explicable under the rules that applied at the time (`REQ-GEO-008`)

### Story 3.6: Create a ticket in two phases

As a player,
I want my stake and my outcome committed together or not at all,
So that I never lose money without getting a ticket.

**Acceptance Criteria:**

**Given** a play request
**When** the eligibility phase runs with no database locks held
**Then** state of play and licence footprint, exclusion registry, RG limits and KYC tier, game registry status and stake range are all checked, a seed is issued, and the engine resolves — with the engine call idempotent on `ticket_id` (`REQ-TKT-002`, `REQ-TKT-011`)

**Given** eligibility passes
**When** the commitment transaction runs
**Then** RG limits, KYC tier and Play Balance are re-asserted inside the transaction, the stake is reserved from Play Balance into `SUSPENSE`, and ticket, outcome and jurisdiction are persisted write-once (`REQ-TKT-012`, `REQ-WAL-020`)
**And** any failure at any step leaves no ticket and moves no money

**Given** the transaction does not commit
**When** the resolved outcome is discarded
**Then** it is never persisted, settled, revealed or counted in statistical monitoring (`REQ-TKT-011`)

**Given** the eligibility gate framework
**When** it is built
**Then** each gate is pluggable and refuses play when its dependency is configured but unsatisfiable, rather than passing on absence (`REQ-GEO-005`, `REQ-RG-014`)
**And** monthly range partitions are defined on `ticket` and `taxDeduction` at creation (`REQ-NFR-032`)

> **Cross-epic dependency, resolved by construction.** This story's gate framework calls the
> exclusion registry and responsible-gambling limits, both of which are built in Epic 5. That is
> not a forward dependency, because of one rule recorded in Story 3.5: **a state cannot enter
> the active licence footprint until an exclusion registry is configured for it.** Before Epic 5,
> no state is licensed, so no real-money play occurs and the gate is exercised only in staging.
> A player with no limits configured passes the limit gate legitimately — that is an absent
> limit, not a bypassed one. Epic 3 is therefore independently buildable and testable, while
> **real-money play in any state requires Epic 5 to be complete.**

### Story 3.7: Prevent outcome leakage before reveal

As a compliance officer,
I want no unrevealed information reachable from any response,
So that the most likely exploit vector is closed.

**Acceptance Criteria:**

**Given** a ticket that has not completed its reveal
**When** any API response is inspected
**Then** it contains no unrevealed numbers, no winning set, no tier, and no field correlated with them (`REQ-TKT-005`)
**And** response size and timing do not correlate with the outcome within tolerance (`REQ-QA-006`)

**Given** the reveal completes
**When** settlement returns
**Then** the full outcome is disclosed at once

### Story 3.8: Compute and deduct withholding tax at settlement

As a player,
I want to see exactly what was deducted and why,
So that a smaller number than I expected is explained rather than suspicious.

**Acceptance Criteria:**

**Given** a winning settlement
**When** tax is applied
**Then** withholding is computed on the basis defined by the applicable state ruleset, at the resident or non-resident rate derived from the KYC record (`REQ-TAX-003`, `REQ-TAX-004`)
**And** a ledger entry is written against `WHT_PAYABLE:{state}` (`REQ-TAX-005`)
**And** the Winnings Balance is credited with the net prize (`REQ-PO-004`)

**Given** the player views the result
**When** the amounts are shown
**Then** the deduction appears before the credited amount, with rate, basis and amount stated (`REQ-TAX-006`)

**Given** a settled ticket
**When** tax is recomputed
**Then** it reproduces exactly, citing the ruleset version used (`REQ-TAX-010`)
**And** a matrix over state, residency, prize, basis definition and ruleset version matches independently computed values (`REQ-QA-018`)

### Story 3.9: Play BlackRed on the web

As a player,
I want to pick colours, set a stake and see my result clearly,
So that I can play and understand what happened.

**Acceptance Criteria:**

**Given** a funded, eligible player
**When** they select 1–5 cards and a colour for each
**Then** predictions are captured as `B` or `R`, and colour is never the sole carrier — every selection carries a letter and a distinct shape or pattern (`REQ-BR-001`, `REQ-BR-022`, UX-DR20)

**Given** a stake is set
**When** the player reaches confirmation
**Then** the commitment summary shows stake, the true probability of the chosen tier, the multiplier, potential return, and that the outcome is determined at purchase (`REQ-BR-012`, `REQ-TKT-010`, UX-DR13)

**Given** the result is returned
**When** it is displayed
**Then** it is conveyed by text as well as animation, a reduced-motion path shows the same result and sequence meaning, and a losing result receives no celebratory treatment (`REQ-RG-022`, UX-DR22)
**And** replay is not the visually dominant action after a loss

**Given** a ticket left in play
**When** `abandon_ttl` elapses
**Then** it auto-settles server-side on its predetermined outcome, pays a winner and notifies (`REQ-TKT-006`, `REQ-QA-022`)

### Story 3.10: Monitor realised outcomes against the model

As a compliance officer,
I want realised frequencies checked against configured probabilities continuously,
So that a divergence is detected rather than discovered.

**Acceptance Criteria:**

**Given** settled tickets
**When** the scheduled monitor runs
**Then** chi-square goodness-of-fit is computed per game over rolling 10k, 100k and 1M ticket windows against configured probabilities, alerting Compliance beyond tolerance (`REQ-RNG-006`)
**And** blended RTP across the realised stake mix is reported to Compliance monthly (`REQ-BR-013`)

---

## Epic 4: Money Out — Payout, Float and Withdrawal

A player can withdraw winnings to their OPay wallet and receive a receipt showing gross, tax and
net; Finance can see float cover and act before it runs out.

### Story 4.1: Dispatch a won prize to the player's OPay wallet

As a winning player,
I want my prize to arrive in my OPay wallet without me doing anything,
So that winning feels finished rather than pending.

**Acceptance Criteria:**

**Given** a winning settlement
**When** the Winnings Balance is credited with the net prize
**Then** disbursement proceeds asynchronously using `createSingleOrder` with `payoutType: OpayWalletNg`, carrying only `customerName` and `phone` in `metaData` (`REQ-PO-001`, `REQ-PO-002`)
**And** the phone number uses the `+234…` format (`REQ-PO-003`)
**And** p95 from settlement to OPay wallet credit is 90 seconds or less, with the Winnings Balance credit effectively instant (`REQ-PO-005`)

**Given** the payout completes
**When** the player views the receipt
**Then** it shows game, ticket reference, gross prize, tax deducted with rate and basis, net paid, destination, OPay order number and timestamp (`REQ-PO-011`, UX-DR14)

### Story 4.2: Handle payout amounts in the correct unit

As a finance operator,
I want request and callback units handled explicitly,
So that reconciliation is not out by a factor of one hundred on every payout.

**Acceptance Criteria:**

**Given** a payout request
**When** it is sent
**Then** `amount` is expressed in kobo (`REQ-PO-012`)

**Given** a payout callback
**When** it is parsed
**Then** `amount` is interpreted as a Naira unit, matching OPay's documented `"2000.00"` form

**Given** the unit test suite
**When** it runs
**Then** it asserts requests are sent in kobo and callbacks parsed as Naira, and fails if either is reversed (`REQ-QA-014`)

### Story 4.3: Handle payout status safely, including unknown codes

As a finance operator,
I want an unrecognised provider status never treated as failure,
So that a player is not told they lost money that is actually in flight.

**Acceptance Criteria:**

**Given** a dispatched payout
**When** status is polled
**Then** `INITIAL`, `PENDING`, `CHECKING`, `SUCCESS`, `FAIL`, `CLOSE` and `RETURN` are handled explicitly (`REQ-PO-006`)
**And** a status outside that enumeration escalates for manual review rather than being treated as failure

**Given** a disbursement fails
**When** retries are exhausted
**Then** it routes to a manual queue while the net prize remains credited in the Winnings Balance throughout (`REQ-PO-009`)

**Given** OPay's dashboard offers a per-payout review requiring an operator email OTP
**When** the integration is configured
**Then** that review is disabled, and Betplus performs its own maker-checker above threshold instead (`REQ-PO-008`)

### Story 4.4: Prevent any ticket paying twice

As a finance operator,
I want duplicate payout structurally impossible,
So that an aggressive retry cannot pay a prize twice.

**Acceptance Criteria:**

**Given** the payout schema
**When** a second payout for a ticket and payout type is attempted
**Then** a hard uniqueness constraint on `(ticketId, payoutType)` refuses it, plus a ticket-state check before dispatch (`REQ-PO-010`)

**Given** settlement and OPay callbacks are replayed aggressively
**When** the test suite completes
**Then** exactly one payout exists per ticket (`REQ-QA-009`)

### Story 4.5: Release a deposit for withdrawal once staked

As a player,
I want to understand exactly when my deposit becomes withdrawable,
So that the rule feels like a condition rather than a trap.

**Acceptance Criteria:**

**Given** a deposit
**When** cumulative stakes on any game equal or exceed that deposit
**Then** it becomes withdrawable from Play Balance, tracked per deposit first-in-first-out, with outcome irrelevant to satisfaction (`REQ-WAL-030`)

**Given** turnover is incomplete
**When** the player views their wallet
**Then** progress shows as a specific figure such as "₦4,200 of ₦10,000 staked", never an unexplained lock (`REQ-WAL-032`, UX-DR18)

**Given** a withdrawal is requested against an un-staked deposit
**When** it is processed
**Then** it is referred to Compliance, and where no suspicion arises funds are released in full less actual processing cost — an un-staked deposit is never permanently retained (`REQ-WAL-031`)

**Given** the turnover multiplier
**When** it is changed
**Then** it changes by configuration under maker-checker, not by deployment (`REQ-WAL-033`)

### Story 4.6: Withdraw from the Winnings Balance

As a player,
I want to move my winnings to my OPay wallet whenever I choose,
So that my money is genuinely mine.

**Acceptance Criteria:**

**Given** a positive Winnings Balance
**When** a withdrawal is requested
**Then** it proceeds without a turnover condition (`REQ-WAL-020` rule 5)
**And** the destination is the registered MSISDN's OPay wallet, and any mismatch is detected rather than merely monitored (`REQ-WAL-021`)

**Given** a withdrawal above `manual_review_threshold`
**When** it is submitted
**Then** it is held for internal maker-checker approval (`REQ-PO-007`)

**Given** the withdrawal path
**When** it is compared with the deposit path
**Then** it is no harder to find than deposit (UX-DR26)

### Story 4.7: Track and reconcile the OPay float

As a finance operator,
I want to know exactly how much cover we have,
So that I can top up before a winner is left waiting.

**Acceptance Criteria:**

**Given** funds transferred to OPay
**When** they are recorded
**Then** an `OPAY_FLOAT` ledger account tracks them, reconciled daily against OPay's balance endpoint (`REQ-FLOAT-001`)
**And** the balance is polled every 60 seconds and after every payout batch (`REQ-FLOAT-002`)
**And** MDR is accrued to `FEES` per payout so true payout cost is visible (`REQ-FLOAT-007`)

**Given** the Finance dashboard
**When** it is opened
**Then** days-of-cover appears as a headline figure alongside balance, burn rate, pending payout value, alert state and top-up history (`REQ-FLOAT-008`, `REQ-BO-009`)

### Story 4.8: Alert on float thresholds and degrade without losing a prize

As a winning player,
I want my prize preserved even when the operator's float runs dry,
So that an operational problem never becomes my loss.

**Acceptance Criteria:**

**Given** float policy is configured
**When** the balance crosses a threshold
**Then** warning below 3× expected daily payout notifies Finance, critical below 1× pages Finance and Ops, and halt below the largest single possible prize suspends automatic disbursement and pages executives (`REQ-FLOAT-003`)
**And** the minimum balance includes headroom for the largest theoretical single prize on any active game (`REQ-FLOAT-004`)

**Given** the float cannot cover a payout
**When** settlement occurs
**Then** the Winnings Balance is still credited, the player is told their winnings are in their balance and the transfer is processing, disbursement queues, and Ops is paged (`REQ-FLOAT-006`)
**And** the player never sees a lost prize — only a delayed transfer

**Given** a simulated `5006 BALANCE NOT ENOUGH`
**When** the test runs
**Then** winnings remain credited, the payout queues, the alert fires and no prize is lost (`REQ-QA-016`)

---

## Epic 5: Responsible Gambling and Exclusion Registry

A player can set limits, take a break or self-exclude across every game and channel, and a person
on a state exclusion registry cannot play — while still being able to withdraw.

### Story 5.1: Set deposit and stake limits across all games

As a player,
I want limits that apply to everything I play,
So that a limit means what I think it means.

**Acceptance Criteria:**

**Given** a player sets a limit
**When** it is applied
**Then** daily, weekly and monthly deposit limits and daily and weekly stake limits apply across all games combined, not per game (`REQ-RG-002`)
**And** session time limits apply on Web and App

**Given** a limit is reduced
**When** the change is submitted
**Then** it takes effect immediately (`REQ-RG-003`)

**Given** a limit is increased
**When** the change is submitted
**Then** it takes effect after 24 hours, so the decision is never made in the moment

**Given** a limit is reached
**When** the player attempts to exceed it
**Then** the action is refused with `RG_LIMIT_EXCEEDED` and the player is notified (`REQ-NOT-003`)

### Story 5.2: Take a break with a cool-off

As a player,
I want to stop for a defined period,
So that I can step back without closing my account.

**Acceptance Criteria:**

**Given** a player chooses a cool-off
**When** they select 24 hours, 7 days or 30 days
**Then** play and deposit are blocked for that period while withdrawal remains available (`REQ-RG-004`)
**And** confirmation is delivered by SMS (`REQ-NOT-003`)

**Given** a player is on a break
**When** any marketing would be sent
**Then** it is suppressed entirely (`REQ-RG-004`, `REQ-NOT-004`)

### Story 5.3: Self-exclude

As a player at risk,
I want to exclude myself irreversibly for a period,
So that the decision cannot be undone in a weak moment.

**Acceptance Criteria:**

**Given** a player self-excludes
**When** the exclusion is applied
**Then** it lasts a minimum of 6 months, is irreversible for that period, and blocks play, deposit and all marketing on every channel and every game (`REQ-RG-005`)
**And** withdrawal remains available throughout

**Given** self-exclusion tooling
**When** a player looks for it
**Then** it is reachable within two screens of the main menu on every channel, and is never hidden, shamed or obstructed (`REQ-RG-008`, UX-DR26)

### Story 5.4: See reality checks and true net position

As a player,
I want to know how long I have played and whether I am up or down,
So that I can make an informed decision.

**Acceptance Criteria:**

**Given** a player has played 20 consecutive times or for 30 minutes
**When** the threshold is reached
**Then** a reality check shows time elapsed, total staked and net position across all games, with a clear exit (`REQ-RG-006`)

**Given** a player opens their account
**When** they look for their position
**Then** net position over 7, 30 and 90 days is visible and not buried (`REQ-RG-007`)

### Story 5.5: Integrate the state exclusion registry, failing closed

As a compliance officer,
I want the state registry treated as authoritative,
So that a licence condition is met rather than approximated.

**Acceptance Criteria:**

**Given** the registry integration
**When** it is built
**Then** the registry is authoritative and the local table is a cache, with matching by NIN (`REQ-RG-011`, `REQ-RG-012`)
**And** the service is written for N registries rather than one (`REQ-RG-017`)

**Given** a player transacts
**When** eligibility is checked
**Then** the registry is checked at registration before the account can transact, before every ticket creation from a cache with maximum staleness of 15 minutes, and on a full daily reconciliation sweep (`REQ-RG-013`)

**Given** the check cannot complete and the cached record exceeds 6 hours
**When** a ticket is requested
**Then** creation is refused with `REGISTRY_UNAVAILABLE` and a clear, non-punitive message (`REQ-RG-014`)

**Given** registry sync
**When** it is monitored
**Then** freshness, cache age and error rate are alerted and shown on the Compliance dashboard (`REQ-RG-018`, M13)

### Story 5.6: Honour a registry exclusion completely

As an excluded person,
I want the exclusion respected everywhere without being asked again,
So that the protection actually protects me.

**Acceptance Criteria:**

**Given** a registry-excluded NIN
**When** play is attempted on Web, App or USSD, on either game
**Then** it is blocked with `REGISTRY_EXCLUDED` (`REQ-QA-021`)

**Given** a registry-excluded player with a positive balance
**When** they access their account
**Then** play and deposit are blocked, marketing is suppressed, and a withdrawal path is preserved (`REQ-RG-015`)

**Given** a registry-excluded player
**When** any communication is considered
**Then** they are never asked to self-exclude with Betplus separately and never contacted to encourage return (`REQ-RG-016`)

### Story 5.7: Apply per-game velocity controls and behavioural flags

As a compliance officer,
I want BlackRed held to stricter velocity limits than Heritage,
So that the faster game carries the tighter control.

**Acceptance Criteria:**

**Given** the RG service
**When** velocity is evaluated
**Then** per-game thresholds apply in addition to platform-wide limits, with BlackRed configured more strictly as a fast-cycle, low-pause game (`REQ-RG-021`)

**Given** player behaviour
**When** it is analysed
**Then** rapid stake escalation, deposit-decline chasing, late-night velocity spikes and sustained low-variance play surface to a human review queue (`REQ-RG-020`)

### Story 5.8: Enforce the ethical design gate as a release blocker

As a compliance officer,
I want the fourteen prohibited patterns checked before release,
So that a dark pattern cannot ship by omission.

**Acceptance Criteria:**

**Given** any player-facing release
**When** it is reviewed
**Then** all fourteen release blockers are checked and recorded: default elevated stake, countdown or scarcity around staking, loss disguised as partial success, near-miss emphasised over factual result, replay visually dominant after a loss, required consent bundled with marketing, "Accept" more prominent than "Decline", withdrawal harder to find than deposit, cool-off hidden or obstructed, promotion shown to a break or excluded player, odds or fees or tax or timing disclosed only after commitment, fake social proof, celebratory animation for a loss, and any interface suggesting reveal choice changes a predetermined outcome (UX-DR26, `REQ-RG-022`)
**And** any failure blocks the release rather than raising a ticket

---

## Epic 6: Back Office and Operations

Operators can resolve a player's problem on one call, approve sensitive changes under
maker-checker, reconcile money, watch float and licences, publish prize tables, and read
first-party analytics.

### Story 6.1: Sign in to the back office under RBAC and MFA

As a system administrator,
I want operator access scoped and protected,
So that the highest-value surface in the system is not the weakest.

**Acceptance Criteria:**

**Given** back-office accounts
**When** they are created
**Then** roles exist for Support Agent, Support Lead, Finance, Compliance, Game Ops, Content Editor, Cultural Reviewer, System Admin and Super Admin, with least privilege (`REQ-BO-001`)
**And** Super Admin can reach every back-office capability but cannot bypass MFA, audit, maker-checker or segregation-of-duties controls
**And** MFA is mandatory for every account and IP allowlisting applies to privileged roles (`REQ-BO-011`)
**And** the back office is served on a network surface distinct from the player API with its own auth guard (`REQ-BO-014`)

**Given** an operator signs in
**When** navigation renders
**Then** destinations their role cannot reach are removed rather than shown disabled (`REQ-BO-013`)
**And** navigation is organised by operator task: Overview, Players, Tickets, Money and reconciliation, Payout float, Responsible play and registry, Jurisdictions and tax, Games and prize tables, Content and Heritage catalogue, Reports and exports, Analytics, Audit log, Users and roles (`REQ-BO-012`, UX-DR29)

### Story 6.2: Record every operator action in an immutable audit log

As a compliance officer,
I want every action attributable,
So that an inspection can be answered from records rather than memory.

**Acceptance Criteria:**

**Given** any operator action
**When** it completes
**Then** the audit log records actor, timestamp, before and after values, IP and justification where required (`REQ-BO-002`)
**And** the log is tamper-evident, shipped off-server, and retained hot for at least 12 months (`REQ-SEC-034`)

**Given** an operator is about to confirm an action
**When** the confirmation is shown
**Then** it displays the permission being exercised, the reason recorded and the audit consequence (`REQ-BO-025`)

### Story 6.3: Approve sensitive changes through one maker-checker workflow

As a compliance officer,
I want every reviewable change to move through the same states,
So that approval is consistent and a new reviewable action is cheap to add.

**Acceptance Criteria:**

**Given** any of the nine change types in `REQ-BO-003`
**When** a change is proposed
**Then** it moves through `DRAFT` → `AWAITING_APPROVAL` → `APPROVED` or `REJECTED` → `APPLIED` using one shared workflow (`REQ-BO-015`)

**Given** a change awaiting approval
**When** maker and checker view it
**Then** the maker supplied a justification and sees a before/after diff, and the checker sees risk context, affected states and games, effective time and validation results (`REQ-BO-016`, UX-DR31)

**Given** the initiator attempts to approve their own change
**When** approval is submitted
**Then** it is refused (`REQ-BO-017`, `REQ-SEC-023`)
**And** no individual who configures a prize table may approve a payout

**Given** a change is rejected
**When** the rejection is recorded
**Then** a reason is required and the draft is preserved (`REQ-BO-018`)
**And** an applied change records immutable actor, approver, version and timestamp

### Story 6.4: Resolve a player's problem from one page

As a support agent,
I want everything about a player on one screen,
So that "I paid but got nothing" is answered on the first call.

**Acceptance Criteria:**

**Given** a player reference
**When** Player 360 is opened
**Then** it shows profile, KYC status, both balances, full ledger, ticket history across games with outcome trails, payment history, tax deductions, notification delivery history, and RG status including registry state — on one task-oriented page (`REQ-BO-004`, UX-DR32)
**And** sensitive identifiers are masked by default, with no raw NIN or BVN shown unless the vault policy explicitly authorises it and the access is individually audited (`REQ-BO-024`)

### Story 6.5: Reconstruct any ticket's outcome from its sealed seed

As a compliance officer,
I want to reproduce any historical outcome on demand,
So that a fairness challenge can be answered with evidence.

**Acceptance Criteria:**

**Given** a ticket reference for any game
**When** the audit tool is used
**Then** it calls that engine's `replay` and displays the full deterministic derivation from the sealed seed (`REQ-BO-005`)

**Given** a settled ticket
**When** an operator attempts to alter its outcome
**Then** it is impossible; remediation is by compensating ledger entry with recorded justification (`REQ-BO-006`)

### Story 6.6: Work the reconciliation exception queue

As a finance operator,
I want the exceptions rather than the totals,
So that my attention goes where something is wrong.

**Acceptance Criteria:**

**Given** nightly reconciliation has run
**When** the console is opened
**Then** it leads with actionable exception queues rather than decorative metric cards (`REQ-BO-019`, UX-DR33)
**And** each exception links to the underlying ledger entries and provider records

**Given** a manual credit or debit is required
**When** it is proposed
**Then** it routes through maker-checker and produces a compensating ledger entry with justification (`REQ-BO-003`)

### Story 6.7: Monitor jurisdictions, licences and tax rulesets

As a compliance officer,
I want licence expiry impossible to miss,
So that we cannot operate unlicensed by accident.

**Acceptance Criteria:**

**Given** the jurisdiction console
**When** it is opened
**Then** it shows per state: licence status and expiry, ruleset version, tax rates, activity volume and remittance status (`REQ-BO-010`)
**And** the compliance dashboard leads with licence expiry, registry freshness, failed checks and per-state obligations (UX-DR33)

**Given** a licence expires
**When** the expiry time passes
**Then** play in that state stops at expiry rather than at the next deployment (`REQ-QA-017`)
**And** expiry within 60 days raises an alert (`REQ-NFR-043`)

**Given** a tax rate or ruleset change
**When** it is applied
**Then** it requires maker-checker, records legal basis and effective date, and leaves historical tickets calculated under the version in force at settlement (`REQ-TAX-011`)

### Story 6.8: Manage the game registry and publish prize tables

As a game operations lead,
I want to suspend a game or publish a table without a deployment,
So that a commercial or regulatory change takes effect immediately.

**Acceptance Criteria:**

**Given** the game registry
**When** a game is configured
**Then** it carries game code, engine endpoint, engine version, active prize table version, min and max stake, enabled channels and enabled states, and status (`REQ-GEC-010`)
**And** it can be suspended platform-wide, per channel or per state by configuration without a deployment (`REQ-GEC-011`)

**Given** a prize table is submitted
**When** the back office evaluates it
**Then** it computes and displays modelled RTP per tier, aggregate modelled RTP, and RTP net of withholding, blocking approval outside the configured bounds (`REQ-GEC-024`)
**And** publication is refused without a recorded actuarial certification reference (`REQ-GEC-025`)

### Story 6.9: Produce financial and per-state regulatory reports

As a compliance officer,
I want an extract filtered to one state's activity for any date range,
So that a regulator request is a download rather than a project.

**Acceptance Criteria:**

**Given** reporting
**When** a financial report is run
**Then** it slices by game and by state across stakes, payouts, GGR, RTP actual versus modelled, provider fees, tax lines and float movements (`REQ-BO-007`)

**Given** a per-state export
**When** it is requested
**Then** it produces CSV and machine-readable output filtered to that state's attributed activity for an arbitrary date range (`REQ-BO-008`)
**And** it runs asynchronously and delivers a signed expiring link rather than streaming synchronously (`REQ-BO-023`)
**And** the export is audit-logged with actor, filter and row count

**Given** monthly reconciliation
**When** it runs
**Then** attributed activity is reconciled against per-state remittances (`REQ-GEO-009`, `REQ-TAX-008`)

### Story 6.10: Read first-party analytics in the back office

As a product lead,
I want funnels and events without sending player data to a third party,
So that measurement does not create a compliance dependency.

**Acceptance Criteria:**

**Given** any tracked event
**When** it is emitted
**Then** it carries event id, name, UTC timestamp, pseudonymised player id, session id, channel, app version, game code and state code (`REQ-ANL-001`)
**And** anything money- or outcome-related is emitted server-side, with client events treated as UX telemetry only (`REQ-ANL-002`)
**And** it lands in a Betplus-owned append-only store surfaced through the back office, with no third-party analytics platform receiving player events (`REQ-ANL-005`)

**Given** the analytics store
**When** dashboards query it
**Then** they read pre-aggregated rollups rather than raw events, so no reporting query can degrade the play path (`REQ-ANL-006`)
**And** raw events are retained 25 months and partitioned monthly

**Given** any analytics payload
**When** it is inspected
**Then** it contains no raw MSISDN, NIN, BVN, date of birth or fine-grained coordinates (`REQ-ANL-003`)

**Given** the six required funnels
**When** they are built
**Then** acquisition through NIN verification to first paid play, USSD play, funding, payout, cross-game and geo attribution are each measurable and segmentable by channel, game, app version, state and locale (`REQ-ANL-007`)
**And** commercial metrics are never presented without their paired harm indicators (`REQ-ANL-008`)

### Story 6.11: Apply the operational table and dashboard pattern

As an operator,
I want tables that behave predictably everywhere,
So that I can work quickly without re-learning each screen.

**Acceptance Criteria:**

**Given** an operator opens the back-office dashboard
**When** the overview loads
**Then** it begins with Today at a Glance, 3–5 role-relevant KPIs, one primary performance chart, Needs Attention and Recent Activity
**And** the first-level navigation is grouped into Dashboard, Customers, Finance, Product, Compliance, Insights and Administration
**And** security implementation language such as capability codes, approved-network details and audit consequences is not shown unless it affects the operator's current action

**Given** a Super Admin opens the dashboard
**When** role preview is used
**Then** the dashboard content changes to that role's simplified view while the Super Admin session and its controls remain unchanged

**Given** any back-office table
**When** it loads
**Then** it defaults to a scoped date range and never silently loads unbounded history (`REQ-BO-021`, UX-DR30)
**And** headers are sticky and sortable, filters show active state and persist in the URL and on return navigation
**And** financial values are right-aligned and status uses a label as well as colour
**And** empty, loading, partial, stale and error states are visually distinct

**Given** a bulk action
**When** it is invoked
**Then** it displays the affected count and requires explicit scoped confirmation (`REQ-BO-022`)

**Given** any chart
**When** it renders
**Then** it carries a text summary, an accessible legend, precise tooltips and access to the underlying table (`REQ-BO-020`)

---

## Epic 7: Heritage — Second Game on the Same Contract

A player can dress a King or Queen in traditional regalia, see the full board at settlement, and
earn automatic entry into a 5/90 draw — proving that a second engine, in a second language, runs
on the same contract.

### Story 7.1: Issue an identical Heritage board on every channel

As a player,
I want the same game whichever device I use,
So that my odds do not depend on how I connect.

**Acceptance Criteria:**

**Given** any Heritage ticket on any channel
**When** the board is issued
**Then** it contains 9 distinct integers drawn without replacement from 1–90 (`REQ-HG-001`)
**And** Web and App render a 3×3 grid of face-down tiles while USSD renders the same nine numbers as a text list, from an identical underlying board (`REQ-HG-002`)

**Given** a player selects positions
**When** the selection is validated
**Then** exactly 5 of the 9 positions are required, and fewer, more or duplicates are rejected (`REQ-HG-003`)

**Given** any channel
**When** its mechanic is compared to another
**Then** board, selection mechanic and odds are identical — no channel presents a variant pick (`REQ-HG-005`, `REQ-QA-010`)

**Given** Quick Pick is used
**When** positions are chosen
**Then** 5 of the 9 are selected uniformly at random server-side, with expected value identical to manual selection (`REQ-HG-004`, `REQ-QA-012`)

### Story 7.2: Resolve the Heritage outcome and produce a consistent reveal

As a compliance officer,
I want the outcome fixed at creation and the reveal derived from it deterministically,
So that the game is certifiable and replayable.

**Acceptance Criteria:**

**Given** a ticket is created
**When** the engine resolves
**Then** it draws an outcome tier from the active prize table by weighted selection, derives match count, generates the board, designates 5 of 9 positions as the Winning Set, and returns board, winning set, tier and match count (`REQ-HG-010`)
**And** the engine holds no state, touches no database and generates no randomness, running multi-process under Gunicorn (`REQ-GEC-002`, `REQ-HOST-004`)

**Given** a player's position selection
**When** the reveal is requested
**Then** board numbers are permuted so exactly `match_count` of the chosen positions fall inside the Winning Set, preserving the multiset of board numbers (`REQ-HG-011`)
**And** the player's choice changes only which numbers and items appear where, never the outcome

**Given** the same `(ticketId, playerSelection)`
**When** the reveal is regenerated
**Then** it is byte-identical (`REQ-HG-012`)

### Story 7.3: Reveal the full board at settlement

As a player,
I want to see all nine positions when the round ends,
So that I can check the result rather than take it on trust.

**Acceptance Criteria:**

**Given** a settled Heritage ticket
**When** the result is shown
**Then** all nine positions are marked as picked-and-winning, picked-and-not, unpicked-but-winning, or unpicked-and-not (`REQ-HG-013`)

**Given** unpicked-but-winning positions
**When** they are displayed
**Then** they are shown factually with no highlight animation and no "so close" copy (`REQ-HG-014`, UX-DR26)

**Given** rules copy
**When** the losing band is described
**Then** it reads "1–2 match", because zero matches is impossible on a 9-tile board carrying 5 winners (`REQ-HG-016`)

### Story 7.4: Fix the 90-item catalogue with an immutable mapping

As a compliance officer,
I want the number-to-item mapping fixed before the first live ticket,
So that audit replay and the collection mechanic remain possible.

**Acceptance Criteria:**

**Given** the catalogue
**When** it is created
**Then** exactly 90 items exist numbered 1–90 with a stable, immutable, one-to-one number-to-item mapping (`REQ-HG-060`)
**And** `itemNumber` is the primary key over 1–90 and item names carry a uniqueness constraint (`REQ-HG-061`)
**And** each item records number, canonical name, local-language name, applicable traditions, leader applicability, body slot, layer priority, cultural description, advisory sign-off reference and depiction constraint (`REQ-HG-062`)

**Given** the first live ticket exists
**When** any operation attempts to change which number maps to which item
**Then** the back office refuses it (`REQ-HG-061`)

**Given** the mapping
**When** it is tested
**Then** it is asserted one-to-one across all 90 numbers and stable across rounds (`REQ-QA-023`)

> **Scope note.** Artwork is deferred. `assetRef` points at a placeholder manifest; the
> immutability constraint is enforced now because renumbering after the first live ticket is
> prohibited.

### Story 7.5: Gate catalogue publication on cultural sign-off

As a cultural advisor,
I want no item published without my recorded approval,
So that sacred regalia is not rendered carelessly as a prize.

**Acceptance Criteria:**

**Given** any catalogue item
**When** publication is attempted
**Then** it is refused without a recorded sign-off reference from a named cultural advisor for that tradition (`REQ-HG-065`)
**And** the review explicitly covers which items may be depicted at all, which require abstraction rather than realistic depiction, and whether the dressing animation is acceptable for sacred items

**Given** the catalogue
**When** it is reviewed
**Then** it depicts no identifiable living monarch, no specific existing palace's regalia, and no item the advisory review flags as restricted (`REQ-HG-066`)

**Given** Yoruba and Igbo item names
**When** they render on Web, the mobile app and SMS
**Then** diacritics display correctly, with a documented transliteration where SMS encoding cannot carry them (`REQ-HG-067`, `REQ-QA-025`)

### Story 7.6: Choose a royal tradition and leader without affecting odds

As a player,
I want to pick a tradition and a King or Queen,
So that the game feels personal — without changing my chances.

**Acceptance Criteria:**

**Given** a player profile
**When** tradition and leader are selected
**Then** they are stored and snapshotted onto each ticket, and enumerated from configuration rather than hardcoded (`REQ-HG-050`, `REQ-HG-054`)

**Given** any tradition or leader
**When** outcomes are analysed across a large simulated population
**Then** no statistically significant difference in outcome distribution exists (`REQ-HG-051`, `REQ-QA-011`)

**Given** tradition and state of play
**When** they are used
**Then** they are never conflated — tradition drives visuals, state drives licensing and tax (`REQ-HG-052`)

**Given** a first-time player
**When** a tradition is suggested
**Then** the suggestion is never derived from name or location inference; all traditions are presented equally (`REQ-HG-053`)

### Story 7.7: Enter the 5/90 second-chance draw

As a player who matched three,
I want an entry into the next draw with proof,
So that a non-winning round still has value I can verify.

**Acceptance Criteria:**

**Given** a `TIER_SECOND_CHANCE` settlement
**When** it completes
**Then** the player's five selected numbers are entered into the next eligible 5/90 draw with a contracted licensed operator (`REQ-HG-030`)
**And** submission is asynchronous, durable and retried, never blocking settlement, queuing in `SECOND_CHANCE_PENDING` (`REQ-HG-034`)

**Given** a successful submission
**When** it is confirmed
**Then** a partner ticket reference is persisted and surfaced, and an entry without a verifiable reference is never presented to the player as lodged (`REQ-HG-035`)
**And** an SMS receipt arrives within 5 minutes carrying the five numbers, draw name and time, partner reference and Betplus ticket reference (`REQ-HG-036`)

**Given** the draw cut-off
**When** an entry settles inside it
**Then** it rolls to the next draw and the player is told which draw they are in (`REQ-HG-032`)
**And** the calendar handles multiple draws per day and same-day schedule shifts from configuration (`REQ-HG-033`)

### Story 7.8: Survive draw-partner failure without blocking play

As a player,
I want a partner outage never to stop me playing or cost me an entry,
So that someone else's downtime is not my problem.

**Acceptance Criteria:**

**Given** sustained partner failure
**When** the circuit breaker opens
**Then** play continues to be accepted, entries queue, Ops is alerted, and beyond a threshold player messaging changes to set correct expectations (`REQ-HG-040`)

**Given** an entry cannot be lodged before cut-off
**When** it rolls
**Then** the player is notified; and if it cannot be lodged within 3 draws the player is compensated per documented policy and told plainly (`REQ-HG-037`)

**Given** daily reconciliation
**When** it runs
**Then** submitted entries are reconciled against partner-confirmed entries, and any record without a counterpart is a P1 exception (`REQ-HG-039`)

**Given** the bridge
**When** it is built
**Then** it targets an internal interface with per-partner adapters, so switching or adding a partner does not touch the Heritage engine (`REQ-HG-031`, `REQ-HG-042`)

### Story 7.9: Notify every second-chance player of the result

As a player in a draw,
I want to hear the outcome either way,
So that I am not left wondering.

**Acceptance Criteria:**

**Given** a draw result is ingested
**When** entries are resolved
**Then** every second-chance player receives a result notification whether they won or not (`REQ-HG-038`)

### Story 7.10: Present Heritage accessibly

As a player using a screen reader,
I want to complete a Heritage round and understand the result,
So that the game is genuinely available to me.

**Acceptance Criteria:**

**Given** a Heritage round in progress
**When** the player reveals tiles
**Then** a running match counter is displayed during play, not only at the end (`REQ-HG-070`)
**And** each revealed item shows its name and one line of cultural context (`REQ-HG-071`)

**Given** a screen-reader user
**When** they play a full Heritage round
**Then** they can complete it, and the nine-position result is navigable as a coherent grid or list after reveal (UX-DR21)

**Given** reduced motion is preferred
**When** the reveal runs
**Then** animation is skipped and results are shown directly, conveying the same sequence meaning (`REQ-HG-072`, `REQ-HG-073`)

**Given** any control
**When** the interface is reviewed
**Then** nothing implies the outcome can be re-rolled, and no "restart round" affordance exists (`REQ-HG-074`)

---

## Epic 8: USSD Channel

A player on a feature phone can register, fund, play both games and reach responsible-gambling
tools — with identical odds to web, inside 160 characters a screen.

### Story 8.1: Run a verified, resumable USSD session

As a feature-phone player,
I want my session to survive a dropped connection,
So that I do not lose my place or my money.

**Acceptance Criteria:**

**Given** a gateway request
**When** it arrives
**Then** its signature and source IP are verified before the asserted MSISDN is trusted (`REQ-ID-004`, `REQ-SEC-009`)

**Given** a session turn
**When** it is processed
**Then** state is held in Redis and mirrored to `ussdSession` for audit, keyed on MSISDN and session id, with row locking per turn (`REQ-USSD-001`)

**Given** a player redials within 10 minutes
**When** the menu renders
**Then** they are offered "Continue your last play" (`REQ-USSD-002`)

**Given** invalid input
**When** it is submitted
**Then** the same screen returns with a one-line error prefix — never a dead end and never a silent drop (`REQ-USSD-004`)

**Given** expired sessions
**When** cleanup runs every 5 minutes
**Then** they are purged (`REQ-USSD-005`)

### Story 8.2: Register over USSD by confirming, not typing

As a feature-phone player,
I want to register without typing my name,
So that registration is possible on a numeric keypad.

**Acceptance Criteria:**

**Given** an unregistered MSISDN dials the shortcode
**When** registration begins
**Then** OPay wallet lookup returns the account holder's name for confirmation rather than entry (`REQ-ID-011`)
**And** date of birth and NIN are captured numerically

**Given** registration completes
**When** the player reaches the main menu
**Then** their balance is shown and both games are selectable from one shared shortcode (`REQ-USSD-001`)

### Story 8.3: Fund in-session without leaving the menu

As a feature-phone player,
I want to add money without dialling another code,
So that I can fund and play in one session.

**Acceptance Criteria:**

**Given** a Tier 1 player selects funding
**When** they enter an amount
**Then** an OPay Bank Account collection is created and the OTP is captured and submitted within the same USSD session (`REQ-USSD-010`)

**Given** the collection does not resolve within 25 seconds
**When** the wait elapses
**Then** the session closes with "We'll SMS you when it lands" and processing continues asynchronously (`REQ-USSD-011`)

**Given** insufficient balance at play time
**When** the player attempts to stake
**Then** funding is offered inline rather than the action simply being refused (`REQ-USSD-012`)

**Given** a player without Tier 1 verification
**When** they attempt Bank Account funding
**Then** they are routed to verification rather than failing opaquely (`REQ-USSD-013`)

### Story 8.4: Play both games over USSD at identical odds

As a feature-phone player,
I want the same games and the same chances as everyone else,
So that my channel is not a lesser product.

**Acceptance Criteria:**

**Given** BlackRed on USSD
**When** the player selects card count, colours and stake and confirms
**Then** the ticket is created through the same platform API as web, with identical odds and prize table (`REQ-HG-005` principle applied platform-wide)
**And** the result screen shows the draw, the player's picks, the outcome, tax deducted and net paid

**Given** Heritage on USSD
**When** the board is shown
**Then** the same nine numbers are listed as text, five are picked or Quick Pick is chosen, and the result shows the match count and the full winning set (`REQ-HG-002`, `REQ-HG-013`)

**Given** the USSD adapter
**When** it is inspected
**Then** it holds no money logic and reaches the wallet only through the platform API

**Given** the USSD information architecture
**When** it is compared with Web and App
**Then** it expresses the same information within the 160-character constraint, in every locale — equivalence rather than a reduced product (UX-DR34)

### Story 8.5: Never lose a funded ticket to a dropped session

As a feature-phone player,
I want a paid ticket honoured even if my session drops,
So that a network problem cannot cost me a win.

**Acceptance Criteria:**

**Given** a funded ticket in play
**When** the session drops
**Then** the ticket auto-settles on its predetermined outcome and the result is delivered by SMS (`REQ-USSD-003`, `REQ-TKT-006`)
**And** a winning abandoned ticket still pays and still notifies

**Given** any USSD outcome
**When** it is produced
**Then** it is mirrored to SMS, because a USSD screen is transient (`REQ-NOT-008`)

### Story 8.6: Reach responsible-gambling tools within two screens

As a feature-phone player,
I want limits and breaks as easy to reach as play,
So that protection is not a second-class feature on my channel.

**Acceptance Criteria:**

**Given** the main menu
**When** the player navigates
**Then** "Set limits" and "Take a break" are reachable within two screens (`REQ-USSD-020`)
**And** the break menu offers 24 hours, 7 days, 30 days, self-exclusion for 6 months, and information about state exclusion

**Given** the state exclusion item
**When** it is opened
**Then** it explains that a state-level exclusion covers all licensed operators and gives the enrolment route, without discouraging enrolment (`REQ-USSD-021`)

### Story 8.7: Enforce the 160-character screen limit in every locale

As a feature-phone player,
I want every screen to display fully,
So that I never see a truncated menu I cannot act on.

**Acceptance Criteria:**

**Given** every rendered USSD screen in every locale
**When** the test suite runs
**Then** each is asserted at 160 characters or fewer including the prompt, and the build fails on overflow (`REQ-QA-013`)
**And** Nigerian Pidgin is included, being frequently longer than the English source

---

## Epic 9: Mobile App

Android and iOS players get a native app from one React Native codebase, hardened with
certificate pinning, root and jailbreak detection, mock-location detection and secure storage.

### Story 9.1: Ship one React Native codebase to both platforms

As a player,
I want a native app on my phone,
So that playing is faster than through a browser.

**Acceptance Criteria:**

**Given** the mobile workspace
**When** it is built
**Then** a single React Native codebase targets Android and iOS (`REQ-APP-001`)
**And** Hermes is enabled on both platforms with ProGuard/R8 and ABI splits on Android, and Android download size is 25 MB or less (`REQ-APP-002`, `REQ-NFR-012`)

**Given** the app routes
**When** they are compared with web
**Then** they mirror the web route shape, while UI components remain platform-specific rather than shared

### Story 9.2: Harden the app with native security modules

As a compliance officer,
I want device-level protections that JavaScript cannot provide,
So that the app is not the weakest way into the platform.

**Acceptance Criteria:**

**Given** the app
**When** it runs
**Then** certificate pinning, root and jailbreak detection, and mock-location detection are implemented as native modules (`REQ-APP-003`, `REQ-SEC-008`)
**And** release builds are obfuscated

**Given** credentials or tokens
**When** they are stored
**Then** they use Keychain on iOS and Keystore on Android through a secure-storage native module, and never AsyncStorage (`REQ-APP-004`)

**Given** mock location is detected
**When** a ticket is requested
**Then** play is blocked and the account is flagged (`REQ-GEO-006`, `REQ-QA-024`)

### Story 9.3: Disable over-the-air updates for regulated surfaces

As a compliance officer,
I want store review to cover every change that touches money or game presentation,
So that we do not bypass review for a regulated product.

**Acceptance Criteria:**

**Given** any release affecting game presentation, money handling, responsible gambling or compliance surfaces
**When** it ships
**Then** over-the-air JavaScript updates are disabled and the change goes through store review (`REQ-APP-005`)

### Story 9.4: Request location with a clear reason and degrade honestly

As a player,
I want to understand why my location is needed,
So that the request feels like compliance rather than surveillance.

**Acceptance Criteria:**

**Given** location permission is requested
**When** the prompt appears
**Then** it carries a clear explanation tied to licensing rather than being buried (`REQ-APP-009`)

**Given** permission is denied
**When** attribution runs
**Then** it routes to a lower-confidence path, and if that fails the refusal is explained rather than silent (`REQ-GEO-005`)

### Story 9.5: Degrade safely when the platform is unreachable

As a player,
I want the app to be honest when it cannot reach the server,
So that I am never misled about my money or a result.

**Acceptance Criteria:**

**Given** the platform is unreachable
**When** the app opens
**Then** it shows the last known balance and a clear "cannot play offline" message, and never simulates a game locally (`REQ-APP-008`)
**And** local connectivity loss is distinguished from a provider delay (UX-DR12)

### Story 9.6: Meet the Heritage animation performance floor

As a player on a low-end device,
I want the reveal to run smoothly,
So that the game is usable on the phone I actually own.

**Acceptance Criteria:**

**Given** a 2 GB RAM Android 10 device
**When** the Heritage reveal animation runs
**Then** it holds 30 fps or better using the native driver (`REQ-APP-010`, `REQ-NFR-013`)
**And** the reduced-motion path exposes the same result and sequence meaning (UX-DR22)

### Story 9.7: Satisfy both stores' real-money gaming requirements

As a product lead,
I want store approval secured before submission,
So that a rejected build does not block launch.

**Acceptance Criteria:**

**Given** the iOS submission
**When** it is prepared
**Then** it is submitted by the licensed entity or its authorised representative, geo-restricted to licensed territories using the same attribution service as play, free to download, and processes no wagers through in-app purchase (`REQ-APP-006`)

**Given** the Android submission
**When** it is prepared
**Then** separate real-money gambling approval is obtained before submission, restricted to supported countries, with age-gating and responsible-gambling information in the listing (`REQ-APP-007`)
**And** the Nigeria outcome is confirmed during Phase 0 (C-10)

---

## Epic 10: Certification, Compliance and Launch Readiness

The platform can be licensed, certified and launched in one state, with every §17.4 release gate
passing and every regulator filing made.

### Story 10.1: Certify the RNG with an accredited laboratory

As a compliance officer,
I want independent certification of the randomness,
So that any state regulator's first question is already answered.

**Acceptance Criteria:**

**Given** the Fairness Service and both engines
**When** certification is performed
**Then** an accredited independent test laboratory certifies the RNG implementation, covering both engines' consumption of platform seeds (`REQ-RNG-005`, `REQ-CERT-001`)
**And** the certificate is held on file and producible to any state regulator on request

### Story 10.2: Certify and file prize tables per licensing state

As a compliance officer,
I want every published table certified and filed,
So that our odds are on the record before a player disputes them.

**Acceptance Criteria:**

**Given** each game's prize table
**When** it is prepared for launch
**Then** it is certified by a qualified game mathematician with the model retained on file (`REQ-COMP-055`, `REQ-CERT-002`)
**And** game rules, prize tables, odds disclosure and RNG certification are filed with each licensing regulator as required (`REQ-COMP-003`)

**Given** published game rules
**When** a player reads them
**Then** they state full odds disclosure and that the outcome is fixed at purchase (`REQ-COMP-052`, `REQ-TKT-010`)

### Story 10.3: Pass an independent penetration test

As a compliance officer,
I want an external assessment before we hold real money,
So that we find what we cannot see ourselves.

**Acceptance Criteria:**

**Given** the threat model documented before build
**When** the penetration test runs
**Then** it covers outcome prediction, reveal-response tampering, payment callback replay, USSD MSISDN spoofing, location spoofing, NIN/BVN harvesting, float manipulation, account takeover and insider prize manipulation (`REQ-SEC-001`, `REQ-SEC-002`)
**And** every Critical and High finding is closed before go-live (`REQ-CERT-004`)

### Story 10.4: Pass OPay sandbox certification

As a payments lead,
I want provider certification complete before production credentials,
So that integration defects surface in sandbox rather than against real money.

**Acceptance Criteria:**

**Given** the OPay integration
**When** it is submitted for certification
**Then** it passes in the sandbox before production credentials are issued (`REQ-CERT-003`)
**And** payout limits, SLA and MDR are confirmed and fed into the float model and manual review threshold (C-03)

### Story 10.5: Obtain registry acceptance and complete regulatory registration

As a compliance officer,
I want every registration complete before beta,
So that we are not processing real money unregistered.

**Acceptance Criteria:**

**Given** operations have not yet commenced
**When** registration is completed
**Then** Betplus is registered with SCUML and enrolled on NFIU goAML before any operations including beta (`REQ-COMP-030`)
**And** a Chief Compliance Officer and a Data Protection Officer are appointed and named (`REQ-COMP-031`, `REQ-COMP-041`)
**And** NDPC registration as a Data Controller of Major Importance is complete before any production personal data is processed (`REQ-COMP-040`)

**Given** the state exclusion registry integration
**When** it is submitted
**Then** it is validated and accepted by the regulator before go-live in that state (`REQ-CERT-005`)

**Given** processors
**When** they are engaged
**Then** data processing agreements exist with OPay, the SMS provider, the identity vendor, the USSD aggregator, the draw partner and the hosting provider (`REQ-COMP-042`)
**And** a DPIA is completed for the play, KYC, location and analytics flows (`REQ-COMP-046`)
**And** the cross-border transfer position is documented and approved (`REQ-COMP-045`, C-11)

### Story 10.6: Validate capacity and arm the scale-out trigger

As a platform engineer,
I want the launch topology proven and the growth trigger armed,
So that we scale ahead of load rather than under it.

**Acceptance Criteria:**

**Given** the tuned cPanel topology
**When** load testing runs
**Then** it is executed against the actual topology rather than a container approximation, validating 300 tickets/s sustained and 800 tickets/s burst for 10 minutes (`REQ-NFR-015`, `REQ-NFR-007`, `REQ-NFR-008`)
**And** per-service resource limits prevent reporting, exports and analytics rollups from starving the play path (`REQ-NFR-017`)

**Given** the validated capacity
**When** monitoring is configured
**Then** sustained peak above 60% of it over a rolling 7-day window fires the scale-out trigger, alerted like a float threshold (`REQ-NFR-016`)
**And** the `REQ-HOST-008` scale-out path has been rehearsed on staging (`REQ-NFR-033`)

**Given** the warm standby
**When** failover is rehearsed
**Then** the runbook is exercised with a named owner and deputy, and both egress IPs are pre-registered with OPay so payments resume (`REQ-NFR-022`, `REQ-NFR-027`)

### Story 10.7: Pass every release gate

As a compliance officer,
I want release blocked unless every gate passes,
So that "we'll fix it after launch" is not available.

**Acceptance Criteria:**

**Given** a release candidate
**When** the gate runs
**Then** all in-scope MUST requirements are verified; Monte Carlo validation passes for the exact prize table shipping for every active game; the RTP ceiling test passes for every tier of every game; Engine Contract conformance passes for every registered engine; the tax correctness matrix passes for every licensed state; fail-closed tests pass; the float exhaustion test passes; zero Critical or High security findings remain open; the reconciliation dry-run passes on a full day of staging data including per-state tax accounts and float; cultural advisory sign-off is recorded for every published Heritage catalogue item; the rollback plan is documented and rehearsed; and Compliance sign-off is recorded (§17.4)

**Given** any UI work
**When** it is accepted
**Then** the anti-slop review's eleven questions are answered with visible evidence and the thirteen-item UX definition of done is satisfied (UX-DR27, UX-DR28)

**Given** the codebase
**When** the naming audit runs
**Then** the canonical product spelling, kobo denomination and Nigerian regulator and provider names are used consistently, with no placeholder or alternate spelling reaching production (`REQ-QA-020`)

### Story 10.8: Run a closed beta in one state

As a product lead,
I want real money in one state before we open the footprint,
So that the geo, tax, registry and float machinery is proven against one real regulator.

**Acceptance Criteria:**

**Given** all release gates pass
**When** closed beta opens
**Then** it runs in a single state — Lagos recommended as the most developed regulatory environment — with real money and a low stake ceiling (§18 Phase 4)
**And** daily reconciliation runs with Finance including float

**Given** the beta period
**When** exit criteria are assessed
**Then** zero reconciliation exceptions have occurred across 14 consecutive days and the per-state remittance dry-run is accepted by Finance

**Given** expansion to a further state
**When** it is prepared
**Then** it is a configuration change plus a compliance filing — and if it is not, the jurisdiction module requires rework (§18 Phase 6)
