# Betplus — Product Requirements Document

**Product:** Betplus — multi-game instant-win gaming platform
**Games:** BlackRed · Heritage
**Market:** Federal Republic of Nigeria
**Payment partner:** OPay (exclusive)
**Channels:** Web · Mobile App (React Native, Android + iOS) · USSD
**Version:** 1.0
**Date:** 10 August 2026

---

## 0. Document Control

| Field | Value |
|---|---|
| Owner | Product Lead, Betplus |
| Contributors | Engineering, Game Math/Actuarial, Compliance & Legal, Payments, Design, QA, Cultural Advisory |
| Status | Draft for approval |
| Governing policy | `BP-AML-001` Anti-Money Laundering Policy |
| Related | Betplus Terms and Conditions · Betplus Brand Guide |
| Review cadence | Weekly until approved; then per change request |

### 0.1 What Betplus is

Betplus is the product and the brand. **BlackRed** and **Heritage** are games inside it.

A player holds one identity, one wallet, one transaction history and one compliance perimeter, and uses them across every game on the platform. The platform provides identity, money, tax, compliance, channels and randomness. Games provide outcomes and nothing else.

### 0.2 The design test

Adding a third game must be **an adapter plus configuration**, not a re-platform. Every architectural decision in this document is measured against that.

### 0.3 Conventions

- Requirements are `REQ-<MODULE>-<NNN>` and are testable.
- **MUST** = launch-blocking. **SHOULD** = expected, deferrable with written sign-off. **MAY** = optional.
- All money is Nigerian Naira, stored as **integer kobo**. ₦1.00 = 100 kobo.
- Sections 8 and 9 are the authoritative rules for BlackRed and Heritage.
- Section 20 lists items awaiting third-party confirmation. Product decisions are stated in place, not deferred.

---

## 1. Executive Summary

Betplus is a multi-game instant-win gaming platform for Nigeria, distributed across Web, a React Native mobile app and USSD, and settled exclusively through OPay.

| Game | Engine | Format | Profile |
|---|---|---|---|
| **BlackRed** | PHP 8.4 | Binary colour prediction, 1–5 cards, escalating multipliers | Fast cycle, low variance at entry tier |
| **Heritage** | Python | Culture-themed instant win, 5 of 9 board, with secondary 5/90 draw entry | Slower, higher variance, retention loop |

### 1.1 Defining decisions

**One wallet, two runtimes.** The PHP Wallet Service is the sole writer to the ledger. The Python engine calls it over an internal API. Two independent implementations of double-entry accounting is how ledgers break, so there is exactly one.

**Games are pure functions.** Given a stake and a seed, an engine returns an outcome. It holds no state, moves no money, generates no randomness and touches no database. This boundary is language-agnostic, which is what allows a PHP game and a Python game to coexist safely and a third game to be written in anything.

**OPay is the only rail, behind an abstraction.** Because an OPay account number is the customer's phone number, identity, registration, funding and payout all key off a single MSISDN. Every capability required has been verified against OPay's published APIs.

**Payouts run on a pre-funded float.** Betplus transfers money to OPay in advance and payouts deduct from that balance. For an instant-payout product this is a first-class operational risk with monitoring, alerting, a cover policy and a runbook.

**A 95% return-to-player ceiling applies platform-wide.** Game margin is an anti-money-laundering control: the house edge is the cost of laundering, and a game returning 100% converts deposits into apparent winnings for free. The publication gate enforces this.

**Nigeria has no federal lottery regulator.** Thirty-six states and the FCT regulate independently. Every stake carries an attributed state that determines licence, tax and reporting.

**Withholding tax is deducted at payout** and remitted per state, alongside a GGR levy and corporate income tax.

**Self-exclusion is partly external.** State registries are authoritative and keyed to NIN, which makes verified NIN a precondition for play.

---

## 2. Goals and Success Metrics

### 2.1 Goals

| ID | Goal |
|---|---|
| G1 | Ship a licensed, compliant multi-game platform across Web, mobile app and USSD |
| G2 | Establish a game-agnostic platform where a third game is an adapter plus configuration |
| G3 | Serve OPay customers end to end — registration, funding, play and payout keyed to the MSISDN |
| G4 | Operate a provably fair, auditable system that survives inspection by any state regulator |
| G5 | Never fail to pay a winner. Float exhaustion, provider outage or session loss may delay a payout; none may lose one |
| G6 | Build state attribution and per-state tax correctly from day one, so expansion is configuration |
| G7 | Make responsible gambling a platform service, not a per-game afterthought |

### 2.2 Non-goals

- Sports betting, casino, virtual sports
- Player-to-player wagering, transfers or social features
- Physical retail agents or POS
- Any payment provider other than OPay
- Any market outside Nigeria
- Play in states outside the licence footprint
- Crypto rails

### 2.3 Metrics

| ID | Metric | Definition | 90-day target |
|---|---|---|---|
| M1 | Activation | Registered users completing ≥1 paid play | ≥ 45% |
| M2 | Cross-game adoption | Activated players who play both games | ≥ 20% |
| M3 | USSD share | Paid plays originating on USSD | ≥ 25% |
| M4 | D30 retention | Activated players staking in days 21–30 | ≥ 25% |
| M5 | Collection success | Successful collections / attempts | ≥ 94% |
| M6 | Payout latency | p95 settlement → OPay wallet credit | ≤ 90 s |
| M7 | Payout success | Disbursements succeeding without manual intervention | ≥ 98% |
| M8 | Float availability | Time float balance above critical threshold | ≥ 99.9% |
| M9 | USSD completion | Sessions reaching a funded ticket / sessions started | ≥ 50% |
| M10 | Platform availability | Monthly uptime of the play path | ≥ 99.9% |
| M11 | Reconciliation | Unreconciled money-movement records at T+1 | 0 |
| M12 | State attribution | Tickets with confidently attributed state | ≥ 99% |
| M13 | Registry compliance | Self-exclusions honoured within SLA | 100% |
| M14 | NIN completion | Registrations reaching verified NIN | ≥ 70% |

M2 justifies the platform architecture. M14 exists because NIN verification is an unavoidable friction point whose failure rate determines whether the acquisition funnel works.

---

## 3. Scope

**Platform:** identity and KYC; dual-balance wallet and ledger; OPay collections and payouts; float management; tax engine; geolocation and state attribution; responsible gambling with registry integration; USSD channel; notifications; RNG and fairness; back office and per-state reporting; analytics.

**Games:** BlackRed engine; Heritage engine; Heritage 5/90 draw partner integration.

**Channels:** responsive Web; React Native app for Android and iOS; USSD across MTN, Airtel, Glo and 9mobile.

**Deferred to v2:** localisation beyond English and Nigerian Pidgin; Heritage collection mechanic; referral programme; additional payment providers; additional games.

---

## 4. Personas

**P1 — Tunde, 29, Lagos, Android, OPay.** Plays betting apps. Wants speed. Funds without thinking. Churns if the app is slow. Primary app persona for BlackRed.

**P2 — Aisha, 44, Kano, feature phone, OPay agent banking.** Buys lotto from an agent. Trusts what she can hold. USSD is not a fallback for her — it is the product.

**P3 — Chinedu, 36, Onitsha, Android, OPay.** Plays 5/90 regularly and knows the draw calendar. Heritage's second chance is what brings him in.

**P4 — Ngozi, 31, Abuja, iPhone, occasional player.** Drawn by Heritage's cultural theme. Likely to share screenshots. Most likely to notice careless regalia rendering.

**P5 — Compliance Officer.** Reconstructs any ticket's outcome, produces per-state extracts, evidences registry sync, demonstrates RNG certification.

**P6 — Finance Operator.** Reconciles the OPay float daily, manages liquidity, monitors tax payable.

**P7 — Support Agent.** Resolves "I paid but got nothing" on one call, across both games.

---

## 5. Architecture

### 5.1 Principles

1. **Server-authoritative.** The client renders. It never decides an outcome, a balance or an eligibility.
2. **Money is a ledger, not a column.** All value movement is double-entry. Balances are derived or reconciled, never directly written.
3. **Games decide outcomes and nothing else.**
4. **One wallet, all games.** Limits, thresholds and monitoring aggregate across the platform.
5. **Idempotent everything.** Every mutating operation carries a caller-supplied key.
6. **Two dependencies fail closed** — geolocation and exclusion registry. Everything else degrades gracefully.
7. **External systems of record are authoritative.** Local copies are caches and are treated as such.
8. **Everything auditable.**

### 5.2 Component view

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CHANNEL ADAPTERS                             │
│   Responsive Web   ·   React Native (iOS/Android)   ·   USSD        │
└────────────────────────────┬────────────────────────────────────────┘
                             │ HTTPS
                  ┌──────────▼───────────┐
                  │  Nginx  ·  API Layer │   TLS · rate limit · WAF
                  └──────────┬───────────┘
                             │
┌────────────────────────────┴────────────────────────────────────────┐
│                  BETPLUS PLATFORM  (PHP 8.4 / PHP-FPM)            │
│                                                                     │
│  Identity &   WALLET &    Payment     Payout &      Tax             │
│  KYC          LEDGER      Orchestr.   FLOAT         Engine          │
│                                                                     │
│  Geo &        Responsible RNG &       Notification  Game Registry   │
│  State        Gaming +    Fairness    (SMS/Push)    & Router        │
│  Attribution  Registry                                              │
└────────────────────────────┬────────────────────────────────────────┘
                             │  Game Engine Contract (HTTP over loopback)
              ┌──────────────┴───────────────┐
              │                              │
   ┌──────────▼──────────┐      ┌────────────▼───────────┐
   │  BLACKRED ENGINE    │      │   HERITAGE ENGINE      │
   │  PHP 8.4            │      │   Python 3.12 (uvicorn)│
   └─────────────────────┘      └───────────┬────────────┘
                                            │
                                 ┌──────────▼───────────┐
                                 │  Draw Partner Bridge │
                                 └──────────────────────┘

┌───────────────┐ ┌──────────────┐ ┌─────────────┐ ┌──────────────────┐
│ MariaDB 10.6+ │ │  OPay APIs   │ │ SMS Gateway │ │ State Exclusion  │
│ Redis         │ │ Pay-in/out   │ │             │ │ Registry         │
└───────────────┘ └──────────────┘ └─────────────┘ └──────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Back Office · Per-State Reporting · Float Console · Audit Store     │
└─────────────────────────────────────────────────────────────────────┘
```

### 5.3 The wallet decision

`REQ-ARCH-001` (MUST) — A single **Wallet Service**, implemented in PHP, is the sole writer to the ledger. It exposes an internal HTTP API. All money movement for all games passes through it.

`REQ-ARCH-002` (MUST) — No game engine, in any language, may write to `ledgerEntry`, `wallet`, `walletTransaction` or any Wallet Service table. Database grants enforce this, not convention.

`REQ-ARCH-003` (MUST) — The Heritage Python engine holds no money state. It receives a stake amount as an input and returns an outcome. It never learns a balance.

Rationale: double-entry invariants, balance derivation and concurrency control are the most dangerous code in the system. Implementing them twice, in two languages, means proving them twice, forever.

### 5.4 Technology stack

| Layer | Decision |
|---|---|
| Platform services & Wallet | **PHP 8.4**, PSR-4, PHP-FPM |
| BlackRed engine | **PHP 8.4** |
| Heritage engine | **Python 3.12**, FastAPI served by Uvicorn |
| Web server | **Nginx** (reverse proxy to PHP-FPM and Passenger) |
| Hosting | **cPanel / WHM on VPS or dedicated** with root access |
| Database | **MariaDB 10.6+**, InnoDB, `utf8mb4_unicode_ci` |
| Cache / sessions | **Redis** (AOF persistence) |
| Mobile app | **React Native**, Hermes engine, Android + iOS |
| Queue | Redis-backed queue with supervised workers |
| Logging | Monolog structured JSON |
| IaC / config | Version-controlled deployment scripts; no manual production edits |

### 5.5 Hosting topology and its constraints

The cPanel/Nginx choice imposes real constraints. They are workable, but they must be designed for rather than discovered.

`REQ-HOST-001` (MUST) — **VPS or dedicated server with root and WHM access.** Shared cPanel hosting cannot provide Redis, persistent queue workers, a Python application server, or a static egress IP. All four are hard requirements.

`REQ-HOST-002` (MUST) — **Static egress IP.** OPay performs IP whitelist verification in both directions. The Betplus outbound IP must be registered on the OPay dashboard for test and production. Changing it breaks payments, so it is under change control.

`REQ-HOST-003` (MUST) — Nginx terminates TLS and reverse-proxies to PHP-FPM (platform and BlackRed) and to the Python application server (Heritage). Engine endpoints bind to loopback only and are not routable externally.

`REQ-HOST-004` (MUST) — The Heritage Python engine runs as a supervised **Uvicorn multi-process deployment** (`fastapi run --workers N`, or `uvicorn --workers N`) behind Nginx. **Multiple worker processes, not threads** — the GIL makes threaded deployment unable to meet throughput.

> **Version note (verified 10 August 2026).** Gunicorn with `uvicorn.workers.UvicornWorker` is no longer the recommended pattern; that worker class has been deprecated since Uvicorn 0.30.0 and split into a separate `uvicorn-worker` package. Uvicorn's own multi-process manager is the current guidance. If Gunicorn is retained for process supervision, use `uvicorn_worker.UvicornWorker` from the split-out package rather than the deprecated built-in.

`REQ-HOST-012` (MUST) — **PHP 8.4.** PHP 8.1 reached end of life and 8.2 is security-only until 31 December 2026; running an unsupported interpreter is an audit finding for a licensed operator independent of any framework requirement. PHP 8.4 carries security support until 31 December 2028 and is installable through cPanel EasyApache 4, satisfying both Laravel 13 (minimum 8.3) and Filament v5 (minimum 8.2).

Where Imunify360 Hardened PHP is present on the host it replaces cPanel's PHP packages from its own repository and can cause EasyApache installs of new PHP versions to fail. Confirm before scheduling the interpreter upgrade.

`REQ-HOST-005` (MUST) — Queue workers are long-running supervised processes, not cPanel cron invocations. Cron granularity is one minute; payout dispatch, callback processing and notification delivery require sub-second pickup. Use systemd units or a supervisor daemon.

`REQ-HOST-006` (MUST) — cPanel cron is used only for genuinely scheduled work: nightly reconciliation, session cleanup, draw result polling, registry sweep, float snapshot, statistical monitoring.

`REQ-HOST-007` (MUST) — PHP-FPM pool sizing, `opcache`, MariaDB buffer pool and Redis `maxmemory` are explicitly tuned and documented. Defaults will not meet the performance targets.

`REQ-HOST-008` (MUST) — **Scale path defined before launch.** The launch topology is a single application server. Growth beyond it requires: read replicas for reporting, a second application server behind a load balancer with Redis-backed shared session state, and database connection pooling. Because the platform is stateless apart from Redis, this is a configuration exercise — but it must be rehearsed, not improvised under load.

`REQ-HOST-009` (MUST) — Automated off-server backups: database at minimum every 15 minutes (binlog shipping), full nightly, with monthly restore drills. cPanel's own backup facility is not sufficient alone for RPO ≤ 1 minute.

`REQ-HOST-010` (MUST) — Staging mirrors production topology — same cPanel version, same PHP and Python versions, same Nginx configuration. A staging environment on different infrastructure will not surface Passenger or FPM issues.

`REQ-HOST-011` (MUST) — Deployment is scripted and atomic (symlinked release directories with rollback). Editing files through cPanel File Manager in production is prohibited.

---

## 6. Game Engine Contract

This section is what makes multiple runtimes safe. Any new game implements this and nothing else.

### 6.1 Responsibility boundary

| Concern | Owner |
|---|---|
| Determine an outcome from a stake and a seed | **Game engine** |
| Resolve that outcome to a prize using a versioned prize table | **Game engine** |
| Identity, eligibility, money, tax, settlement, notification, audit | **Platform** |

`REQ-GEC-001` (MUST) — A game engine is a **pure function of its inputs**. Identical inputs return identical outputs, on every invocation, host, restart and deployment of the same engine version.

`REQ-GEC-002` (MUST) — A game engine holds no persistent state. It does not read or write the database, call external services, or write to the filesystem.

`REQ-GEC-003` (MUST) — A game engine never generates randomness. It receives a seed from the platform Fairness Service.

### 6.2 Interface

Three endpoints over HTTP on loopback:

```
POST /engine/v1/resolve
POST /engine/v1/replay
GET  /engine/v1/describe
```

**`resolve`**

```json
{
  "ticket_id":           "uuid",
  "game_code":           "BLACKRED",
  "stake_kobo":          100000,
  "prize_table_version": 3,
  "seed":                "hex-encoded 32 bytes",
  "player_input":        { "...game specific..." },
  "engine_version":      "1.0.0"
}
```

```json
{
  "ticket_id":        "uuid",
  "outcome_tier":     "TIER_WIN_3",
  "gross_prize_kobo": 700000,
  "engine_state":     { "...for display and audit..." },
  "engine_version":   "1.0.0",
  "digest":           "sha256 of the canonical outcome"
}
```

**`replay`** — same request, byte-identical response. Used by audit tooling and determinism tests.

**`describe`** — declared capabilities: supported prize table schema, input schema, tier enumeration, modelled RTP per tier. The platform validates configuration against this before publication.

### 6.3 Game Registry

`REQ-GEC-010` (MUST) — Every game is registered with: `gameCode`, engine endpoint, engine version, active prize table version, min and max stake, enabled channels, enabled states, status (`ACTIVE`, `SUSPENDED`, `RETIRED`).

`REQ-GEC-011` (MUST) — A game may be suspended platform-wide, per channel or per state, by configuration, without a deploy.

`REQ-GEC-012` (MUST) — Ticket creation routes to the engine named on the ticket. The platform never hardcodes a game.

### 6.4 Prize tables

`REQ-GEC-020` (MUST) — Prize tables are **configuration, not code** — versioned, effective-dated, scoped to a game, changed through back office with maker-checker approval.

`REQ-GEC-021` (MUST) — Every ticket records the `prizeTableVersion` in force at creation. Settlement uses that version permanently.

`REQ-GEC-022` (MUST) — Tier probabilities must sum to exactly 1.0000. The system rejects any table that does not.

`REQ-GEC-023` (MUST) — **Platform RTP ceiling of 95%.** No game and no individual wager tier may be published with a modelled theoretical return to player above this. This is an AML control under `BP-AML-001` §7.2: the house edge is the cost of laundering, and at 100% RTP conversion of deposits into apparent winnings is free.

`REQ-GEC-024` (MUST) — Back office computes and displays, before approval: modelled RTP per tier, aggregate modelled RTP, and RTP net of withholding tax. Approval is blocked outside `[rtp_min, rtp_max]`.

`REQ-GEC-025` (MUST) — Publication requires a recorded actuarial certification reference. The system refuses to publish without one.

`REQ-GEC-026` (MUST) — A prize table MAY be scoped per state where a state imposes different constraints. Resolution is by `(gameCode, stateCode, effectiveDate)`.

### 6.5 New game admission

`REQ-GEC-030` (MUST) — No game is admitted until a written AML risk assessment is approved by the Chief Compliance Officer, covering modelled RTP at every tier, variance profile, cycle speed, maximum theoretical conversion rate from Play Balance to Winnings Balance, player-to-player interaction, and any capability to influence outcome distribution.

---

## 7. Platform Services

### 7.1 RNG and Fairness

`REQ-RNG-001` (MUST) — All game randomness derives from a CSPRNG. The Fairness Service seeds a NIST SP 800-90A CTR_DRBG from OS entropy (`random_bytes()`).

`REQ-RNG-002` (MUST) — The Fairness Service issues one seed per ticket. Engines consume it. No engine generates entropy.

`REQ-RNG-003` (MUST) — Every ticket persists `rngSeedRef`, `rngAlgorithm` and `engineVersion`, sufficient to reconstruct the outcome exactly.

`REQ-RNG-004` (MUST) — Seed records are written to an append-only store with cryptographic chaining — each record includes the hash of its predecessor — so tampering is detectable.

`REQ-RNG-005` (MUST) — The RNG implementation is certified by an accredited independent test lab (GLI, BMM, eCOGRA or equivalent) before launch. The certificate is held on file and produced to any state regulator on request.

`REQ-RNG-006` (MUST) — Statistical monitoring runs chi-square goodness-of-fit on realised tier frequencies against configured probabilities, **per game**, over rolling 10k / 100k / 1M ticket windows, alerting Compliance beyond tolerance.

`REQ-RNG-007` (MUST) — **PHP:** `rand()`, `mt_rand()`, `shuffle()`, `array_rand()` and `str_shuffle()` are prohibited in the game and money paths. `mt_rand` is a Mersenne Twister and is predictable from observed output. Static analysis fails the build on their presence.

`REQ-RNG-008` (MUST) — **Python:** the `random` module is prohibited in the Heritage engine for any purpose touching outcome. Same rationale, same enforcement.

`REQ-RNG-009` (SHOULD) — Publish a provably-fair verification page: publish `hash(serverSeed || ticketId)` at ticket creation, reveal `serverSeed` after settlement, allowing independent verification.

### 7.2 Identity and KYC

#### 7.2.1 Identity

`REQ-ID-001` (MUST) — The primary identifier is the Nigerian MSISDN in E.164 (`+234XXXXXXXXXX`). All input formats normalise on ingest.

`REQ-ID-002` (MUST) — One player account per MSISDN. Web, app and USSD sessions resolve to the same account, wallet and history across both games.

`REQ-ID-003` (MUST) — MSISDN ownership is verified by OTP on Web and app (6 digits, 5-minute TTL, max 5 attempts, exponential backoff, max 3 resends per hour).

`REQ-ID-004` (MUST) — On USSD the MSISDN is supplied by the telco gateway and treated as implicitly verified. The adapter validates the gateway signature before trusting it. An unauthenticated request able to assert an arbitrary MSISDN is a total account-takeover vector.

#### 7.2.2 OPay-assisted registration

`REQ-ID-010` (MUST) — Registration calls OPay `POST /api/v1/international/payout/opay-wallet-validate` with the MSISDN, returning `firstName` and `lastName` of the account holder.

`REQ-ID-011` (MUST) — The returned name populates `registeredName` and is presented for confirmation. The player never types their name on USSD.

`REQ-ID-012` (MUST) — A MSISDN with no OPay wallet returns `NO_OPAY_WALLET`. The player is told plainly that a Betplus account requires an OPay wallet, with the route to open one. This is a deliberate consequence of the exclusive partnership and its refusal rate is monitored.

`REQ-ID-013` (MUST) — The OPay-returned name is authoritative for payout name-matching. Player-supplied names never override it.

#### 7.2.3 KYC tiers

`REQ-ID-020` (MUST) —

| Tier | Requirements | Deposit limit | Play | Withdrawal |
|---|---|---|---|---|
| **Tier 0** | Verified MSISDN + OPay wallet validated | ₦10,000 cumulative | No | No |
| **Tier 1** | + date of birth + **verified NIN** | ₦200,000 / month | Yes | To the registered MSISDN's OPay wallet only |
| **Tier 2** | + **verified BVN**, name-matched | Configurable, higher | Yes | Verified alternate accounts permitted |

`REQ-ID-021` (MUST) — **Verified NIN is a precondition for play beyond Tier 0.** State self-exclusion registries are keyed to NIN; without one the exclusion check cannot be performed, so play cannot be permitted.

`REQ-ID-022` (MUST) — NIN and BVN verification is performed by a contracted identity vendor. OPay expects the merchant to already hold the BVN; it does not verify identity.

`REQ-ID-023` (MUST) — NIN and BVN are stored in a vault table with independent access control. Application services receive tokens or hashes, never raw values. They are never logged, never sent to analytics, never returned in full by any API, and every access is audited.

`REQ-ID-024` (MUST) — 18+ affirmation with date of birth at registration. Under-18 accounts are blocked from play and deposit; any balance is returned via reviewed refund and the case is reported.

`REQ-ID-025` (MUST) — Records retained **seven years** from last transaction, exceeding the five-year statutory minimum.

`REQ-ID-026` (MUST) — Access token TTL ≤ 30 minutes; refresh token TTL ≤ 30 days with rotation and reuse detection. Web uses `HttpOnly`, `Secure`, `SameSite=Lax` cookies.

`REQ-ID-027` (SHOULD) — Device fingerprinting, with step-up verification for withdrawals from an unseen device.

### 7.3 Payments — OPay Collections

#### 7.3.1 Method matrix

All collection methods use `POST /api/v1/international/payment/create`, differentiated by `payMethod`.

| Method | `payMethod` | Next action | Web | App | USSD |
|---|---|---|---|---|---|
| **Bank Account debit** | `BankAccount` | `INPUT_OTP` / `INPUT_PIN` / `REDIRECT_3DS` | ✅ | ✅ | ✅ |
| Bank Transfer | `BankTransfer` | account details | ✅ | ✅ | ⚠️ async |
| Bank USSD | `BankUssd` | `SHOW_USSD` | ✅ | ✅ | ❌ |
| Wallet QR | `OpayWalletNgQR` | `SCAN_QR_CODE` | ✅ | ✅ | ❌ |
| Card 3DS | `3DS` | browser redirect | ✅ | ✅ | ❌ |

`REQ-PAY-001` (MUST) — **Bank Account + OTP is the primary funding path on all channels.** The OTP and PIN submission steps are server-side API calls, so the flow is driven entirely from Betplus's own interface — including a USSD menu. Bank Transfer is the fallback.

`REQ-PAY-002` (MUST) — Bank USSD is not offered on the USSD channel. It returns a code the player must dial, which would terminate the Betplus session.

`REQ-PAY-003` (MUST) — Because an OPay account number is the customer's phone number, `bankAccountNumber` is derived from the registered MSISDN and `bankCode` is OPay's constant. The player supplies only the amount and the OTP.

`REQ-PAY-004` (MUST) — `BankAccount` requires `bvn`, `dobDay`, `dobMonth`, `dobYear` and `customerName`. These are collected once at verification and reused. A player without completed verification cannot use this method, consistent with `REQ-ID-021`.

#### 7.3.2 Integration

`REQ-PAY-010` (MUST) — **Two distinct signature schemes exist and must not be confused:**

| Surface | Algorithm | Key material |
|---|---|---|
| Collections | HMAC-SHA512 of the JSON body | Merchant secret key |
| **Payouts** | **RSA-SHA256**, 2048-bit | Merchant-generated key pair; public key submitted on the OPay dashboard and reviewed by OPay |

Both send `Authorization: Bearer {signature}` with a `MerchantId` header, which makes the difference easy to miss. Separate signer classes, separate configuration, separate tests.

`REQ-PAY-011` (MUST) — RSA key pairs are generated separately for test and production, 2048-bit, private keys in the secret store and never in source control.

`REQ-PAY-012` (MUST) — All collections are idempotent on a Betplus-generated `reference`. `merchantOrderNo` is capped at **32 digits** and uniqueness is the merchant's responsibility. Replayed callbacks must not produce duplicate ledger entries; an "order already exists" response is handled as success-with-existing-order, not failure.

`REQ-PAY-013` (MUST) — Callback endpoints verify the signature and source-IP allowlist. Unsigned or unverifiable callbacks are logged and rejected.

`REQ-PAY-014` (MUST) — Where a callback is not received within `callback_timeout` (default 90 s), the orchestrator polls `POST /api/v1/international/payment/status` with exponential backoff for up to 24 hours before marking `UNKNOWN` and escalating. A payment is never assumed failed on timeout alone.

`REQ-PAY-015` (MUST) — Pending-payment tickets are never revealed or settled. A ticket enters `FUNDED` only on confirmed collection.

`REQ-PAY-016` (MUST) — **The Payment Orchestrator abstracts the provider** behind `collect()`, `status()`, `nameLookup()`, `disburse()`, `reconcile()`. OPay is the only implementation at launch. Adding a second provider must not touch the Wallet, Game Registry or any engine.

`REQ-PAY-017` (MUST) — Provider settlement files are ingested daily and reconciled line by line against the ledger.

`REQ-PAY-018` (MUST) — Failed collections release any hold immediately and notify the player in plain language.

`REQ-PAY-019` (MUST) — Every OPay request and response is logged immutably to `opayApiCallLog` with endpoint, reference, outcome and latency.

### 7.4 Wallet and Ledger

#### 7.4.1 Accounting

`REQ-WAL-001` (MUST) — Double-entry accounting. Every monetary transaction creates balanced debit and credit `ledgerEntry` records. For any `walletTransaction`, debits minus credits equals zero. Any imbalance is a P0 incident.

`REQ-WAL-002` (MUST) — Truth lives in `ledgerEntry`. Balances are transactionally cached with optimistic concurrency control via a `version` column. No path writes a balance without the corresponding journal.

`REQ-WAL-003` (MUST) — Denomination is **integer kobo** (`BIGINT`). Floating-point and decimal arithmetic are prohibited throughout. Currency `NGN` is explicit on every amount.

`REQ-WAL-004` (MUST) — Ledger entries are append-only. No `UPDATE` or `DELETE` on the journal, enforced by database permissions and triggers.

`REQ-WAL-005` (MUST) — Negative balances are impossible, enforced at database level.

#### 7.4.2 Dual balance

`REQ-WAL-010` (MUST) — Every account operates two balances, per `BP-AML-001` §4:

| Balance | Account type | Funded by | Use |
|---|---|---|---|
| **Play Balance** | `PLAYER_PLAY` | Deposits only | Sole source of stakes across all games |
| **Winnings Balance** | `PLAYER_WINNINGS` | Confirmed winnings only | Freely withdrawable |

`REQ-WAL-011` (MUST) — The wallet is **platform-level**. One Play Balance funds stakes on every game; one Winnings Balance receives winnings from every game. Players do not and cannot hold per-game balances.

`REQ-WAL-012` (MUST) — Named accounts at minimum: `PLAYER_PLAY:{id}`, `PLAYER_WINNINGS:{id}`, `SUSPENSE`, `HOUSE_REVENUE`, `PAYMENT_CLEARING:OPAY`, `OPAY_FLOAT`, `PRIZE_LIABILITY`, `WHT_PAYABLE:{state}`, `GGR_LEVY_PAYABLE:{state}`, `FEES`, `DRAW_TICKET_COST`.

Tax accounts are **partitioned by state**, because withholding is remitted to a state revenue service and the GGR levy is payable per state. A single national account would make remittance unauditable.

#### 7.4.3 Fund flow

| Rule | Movement | Constraint |
|---|---|---|
| 1 | Deposit → Play Balance | Deposits never credit Winnings Balance |
| 2 | Winnings → Winnings Balance | Winnings never credit Play Balance |
| 3 | Winnings → Play Balance | Unrestricted |
| 4 | Play Balance → OPay wallet | **After 1× turnover** |
| 5 | Winnings Balance → OPay wallet | Unrestricted |
| 6 | Any → third-party account | **Prohibited** |

`REQ-WAL-020` (MUST) — Stake placement debits Play Balance into `SUSPENSE`. On a win, the prize credits from `SUSPENSE` to Winnings Balance. On a loss, the stake moves from `SUSPENSE` to `HOUSE_REVENUE`.

`REQ-WAL-021` (MUST) — Rule 6 is enforceable automatically because identity, funding instrument and payout destination are the same MSISDN. A mismatch is detected, not merely monitored.

#### 7.4.4 Turnover requirement

`REQ-WAL-030` (MUST) — A deposit becomes withdrawable from Play Balance once cumulative stakes equal or exceed that deposit. Turnover is tracked per deposit, first-in-first-out. **Stakes on any game count.** The player need not win; outcome is irrelevant.

`REQ-WAL-031` (MUST) — Where a player requests withdrawal of a deposit that has not satisfied turnover, the request is referred to Compliance. Where no suspicion arises, funds are released in full, less actual processing cost. **The platform never permanently retains an un-staked deposit.**

`REQ-WAL-032` (MUST) — Turnover progress is displayed as a specific figure ("₦4,200 of ₦10,000 staked"), never as an unexplained lock.

`REQ-WAL-033` (MUST) — The turnover multiplier is held in configuration, adjustable with maker-checker approval rather than a deploy.

#### 7.4.5 Integrity

`REQ-WAL-040` (MUST) — Every financial operation requires a globally unique `refNumber` idempotency key.

`REQ-WAL-041` (MUST) — Nightly reconciliation compares cached balances against summed ledger for every active wallet; clearing accounts against OPay settlement files; prize liability against disbursed payouts; and tax payable accounts against remittances made. Discrepancies raise P1 and escalate to Finance same-day. Target: zero unreconciled records at T+1.

### 7.5 Payout Engine and Float

#### 7.5.1 Payouts

`REQ-PO-001` (MUST) — Payouts use `POST /api/v1/international/payout/createSingleOrder` with `payoutType: OpayWalletNg`.

`REQ-PO-002` (MUST) — An `OpayWalletNg` payout requires only `customerName` and `phone` in `metaData` — no account number, no bank code. Phone numbers use the `+234…` format per OPay's stated rule.

`REQ-PO-003` (MUST) — On a winning settlement, the **Winnings Balance is credited with the net prize** (gross less withholding) immediately. Disbursement proceeds asynchronously per player preference.

`REQ-PO-004` (MUST) — Target p95 settlement → OPay wallet credit ≤ 90 seconds. Winnings Balance credit is effectively instant.

`REQ-PO-005` (MUST) — Status is polled via `POST /api/v1/international/payout/queryorder`. The status enumeration is `INITIAL, PENDING, CHECKING, SUCCESS, FAIL, CLOSE, RETURN`. **A status outside this list must not be treated as failure** — OPay reserves the right to add codes. Unknown statuses escalate for manual review.

`REQ-PO-006` (MUST) — Payouts above `manual_review_threshold` (default ₦1,000,000) are held for internal maker-checker approval.

`REQ-PO-007` (MUST) — **OPay-side payout review is disabled.** The merchant dashboard offers a per-payout review requiring an OTP to an operator's email, which is incompatible with instant automated payout. Betplus performs its own maker-checker above threshold.

`REQ-PO-008` (MUST) — Disbursement failures retry with exponential backoff, then route to a manual queue. **The net prize remains credited in the Winnings Balance throughout.** A failed disbursement never removes a won prize.

`REQ-PO-009` (MUST) — Duplicate payout prevention: hard uniqueness constraint on `(ticketId, payoutType)`, plus a ticket-state check before dispatch.

`REQ-PO-010` (MUST) — Every payout produces a player-visible receipt: game, ticket reference, gross prize, tax deducted with rate and basis, net paid, destination, OPay `orderNo`, timestamp.

`REQ-PO-011` (MUST) — **Unit handling.** Payout requests carry `amount` in **kobo**. The payout callback reports `amount` in **Naira**. Explicit unit handling on both sides, with a test asserting it. Parsing the callback as kobo produces a 100× reconciliation error on every payout.

#### 7.5.2 Float management

Betplus pre-funds OPay. Payouts deduct from the merchant available balance plus MDR — a ₦100 payout at 1% deducts ₦101. Exhaustion returns `BALANCE NOT ENOUGH`.

For an instant-payout gambling product this is a live operational risk: a jackpot cluster can drain the float, payouts begin failing, and players who have won money cannot be paid. That is a trust event before it is a technical one.

`REQ-FLOAT-001` (MUST) — An `OPAY_FLOAT` ledger account tracks funds transferred to OPay, reconciled daily against `POST /api/v1/international/payout/balance` (`type: CASH_ACCOUNT`).

`REQ-FLOAT-002` (MUST) — Float balance is polled every 60 seconds and after every payout batch, and cached for dashboard display.

`REQ-FLOAT-003` (MUST) — Tiered alerting:

| Threshold | Default | Action |
|---|---|---|
| **Warning** | Below 3× expected daily payout | Notify Finance |
| **Critical** | Below 1× expected daily payout | Page Finance and Ops; initiate top-up |
| **Halt** | Below the largest single possible prize | Suspend automatic disbursement; queue payouts; page executives |

`REQ-FLOAT-004` (MUST) — Float policy: minimum balance is a configured multiple of expected daily payout **plus headroom for the largest theoretical single prize across all active games**. A maximum-stake maximum-multiplier win must be payable at all times.

`REQ-FLOAT-005` (MUST) — A documented top-up runbook exists with a named owner, a deputy and an out-of-hours escalation path. Float top-up is not an engineering task.

`REQ-FLOAT-006` (MUST) — **Graceful degradation at halt.** The Winnings Balance is still credited, the player is told their winnings are in their balance and the transfer is processing, disbursement queues, and Ops is paged. The player never sees a lost prize, only a delayed transfer.

`REQ-FLOAT-007` (MUST) — MDR is accrued to `FEES` per payout so true payout cost is visible and burn projections account for it.

`REQ-FLOAT-008` (MUST) — Float days-of-cover is a headline figure on the Finance dashboard.

### 7.6 Tax Engine

`REQ-TAX-001` (MUST) — Three layers computed and recorded per ticket and per period:

| Layer | Basis | Nature | Rate |
|---|---|---|---|
| **Withholding on winnings** | Player's net winnings, per state definition | Deducted at payout, remitted to the State Internal Revenue Service | 5% resident / 15% non-resident |
| **GGR levy** | Gross gaming revenue | Operator cost, payable to the state | 11% |
| **Corporate income tax** | Taxable profit | Operator cost, annual | Up to 30% |

`REQ-TAX-002` (MUST) — **All rates and bases are configuration**, effective-dated and per-jurisdiction. No tax rate is a constant in code.

`REQ-TAX-003` (MUST) — Withholding is computed on the **basis defined by the applicable state ruleset**, which may be net winnings rather than gross prize. The basis definition is part of the ruleset, not an assumption. Getting this wrong mis-pays every winning player.

`REQ-TAX-004` (MUST) — Residency status is derived from the KYC record, never inferred.

`REQ-TAX-005` (MUST) — Every deduction produces a ledger entry against `WHT_PAYABLE:{state}` and a player-visible line on the receipt showing rate, basis and amount.

`REQ-TAX-006` (MUST) — The player sees the deduction **before** the credited amount. A player expecting ₦200,000 who receives ₦190,000 without explanation reads it as theft, and that support cost is avoidable.

`REQ-TAX-007` (MUST) — GGR is accrued continuously, not computed at month-end, so liability is visible in real time.

`REQ-TAX-008` (MUST) — Per-state remittance reports on the required cycle, reconciled against ledger balances.

`REQ-TAX-009` (MUST) — Stakes are configured **VAT-exempt** per the Nigeria Tax Act 2025, effective 1 January 2026, held as a flag rather than an assumption.

`REQ-TAX-010` (MUST) — Tax computation is replayable: given a settled ticket, the engine reproduces the exact deduction and cites the ruleset version.

`REQ-TAX-011` (MUST) — A tax change requires maker-checker approval and records the legal basis and effective date. Historical tickets remain calculated under the version in force at settlement.

### 7.7 Geolocation and State Attribution

Nigerian states regulate gaming independently and operators are expected to capture verified location and reconcile remittances per state. Attribution is a compliance control with money attached.

`REQ-GEO-001` (MUST) — Every ticket carries an attributed `stateCode` and `attributionConfidence`, resolved **before** ticket creation and persisted immutably.

`REQ-GEO-002` (MUST) — Attribution combines multiple signals with documented precedence:

| Signal | Availability | Reliability |
|---|---|---|
| Device GPS | App, consent-gated | High |
| Coarse network location | App | Medium |
| IP geolocation | Web, App | Medium — VPN detection required |
| USSD gateway cell data | USSD, where supplied | Medium–High |
| Declared address at KYC | All | Medium — stable but may be stale |
| MSISDN prefix | All | **Not used** |

`REQ-GEO-003` (MUST) — **Never infer state from MSISDN prefix.** Nigerian mobile numbers are portable and not geographically bound. Any design treating a prefix as a location signal will mis-remit tax.

`REQ-GEO-004` (MUST) — If the attributed state is outside the active licence footprint, ticket creation is refused with a clear message. No money moves and no ticket exists.

`REQ-GEO-005` (MUST) — **Fail closed on low confidence.** Below `geo_min_confidence` (default 0.80), ticket creation is refused rather than defaulting to a state. Guessing means remitting to the wrong revenue service and potentially operating unlicensed.

`REQ-GEO-006` (MUST) — VPN, proxy and mock-location detection on Web and app, with a documented response. Detected spoofing blocks play and flags the account.

`REQ-GEO-007` (MUST) — The attributed state drives: licence check, prize table resolution, withholding rate and basis, GGR attribution, reporting bucket, and exclusion registry selection.

`REQ-GEO-008` (MUST) — Attribution logic is versioned (`jurisdictionRulesetVersion`) and recorded per ticket, so a historical ticket is explicable under the rules that applied at the time.

`REQ-GEO-009` (MUST) — Monthly reconciliation of attributed activity against per-state remittances.

`REQ-GEO-010` (MUST) — Location is personal data. Collect with a stated lawful basis, minimise — store the resolved state and confidence, not a continuous trail — and exclude fine granularity from analytics.

`REQ-GEO-011` (MUST) — Back office displays attribution failure rate by channel and state. A spike is the earliest signal of either a provider change or an evasion attempt.

### 7.8 Notifications

`REQ-NOT-001` (MUST) — SMS is the guaranteed channel; push is best-effort; in-app inbox supplements both.

`REQ-NOT-002` (MUST) — Templated, versioned messages with variable substitution. No message strings in code.

`REQ-NOT-003` (MUST) — Mandatory transactional messages: OTP; deposit confirmed; ticket funded; win confirmation with **gross, tax and net**; payout dispatched; Heritage draw ticket receipt; draw result; withdrawal completed; limit reached; break or self-exclusion confirmed.

`REQ-NOT-004` (MUST) — Marketing requires separately recorded opt-in, carries opt-out, and is **suppressed entirely for anyone self-excluded, on a break, or registry-excluded**. Marketing to a registry-excluded person is a licence-condition breach.

`REQ-NOT-005` (MUST) — Delivery receipts captured. Undeliverable MSISDNs flagged after repeated failure.

`REQ-NOT-006` (MUST) — Quiet hours (default 21:00–07:00 WAT) apply to marketing only, never transactional.

`REQ-NOT-007` (MUST) — USSD outcomes are always mirrored to SMS. A USSD screen is transient; without SMS the player has no record.

`REQ-NOT-008` (SHOULD) — Message copy available in Nigerian Pidgin alongside English from launch.

### 7.9 Responsible Gambling

#### 7.9.1 Player tools

`REQ-RG-001` (MUST) — 18+ only, with visible marks on all channels including USSD.

`REQ-RG-002` (MUST) — Player-settable limits applying **across all games combined**: daily/weekly/monthly deposit; daily/weekly stake; session time on Web and app.

`REQ-RG-003` (MUST) — Reducing a limit takes effect immediately. Increasing takes effect after 24 hours, so the decision is never made in the moment.

`REQ-RG-004` (MUST) — Cool-off of 24 hours, 7 days or 30 days. Play and deposit blocked; withdrawal remains available.

`REQ-RG-005` (MUST) — Self-exclusion: minimum 6 months, irreversible for the period, blocking play, deposit and marketing on every channel and every game. Withdrawal remains available.

`REQ-RG-006` (MUST) — Reality checks on Web and app after 20 consecutive plays or 30 minutes, showing time elapsed, total staked and net position across all games, with a clear exit.

`REQ-RG-007` (MUST) — Net position over 7/30/90 days is always visible and not buried.

`REQ-RG-008` (MUST) — Help resources and support contact on every channel, including a USSD menu item within two screens of the main menu.

#### 7.9.2 State exclusion registry

`REQ-RG-010` (MUST) — Betplus integrates with the state-operated self-exclusion registry in each licensed state that operates one. Integration is a licence condition, not an enhancement.

`REQ-RG-011` (MUST) — The registry is **authoritative**. Where it says a person is excluded, Betplus excludes them. The local table is a cache.

`REQ-RG-012` (MUST) — Matching is by **NIN**, which is why verified NIN is a precondition for play.

`REQ-RG-013` (MUST) — The registry is checked at registration before the account can transact; before every ticket creation from a cache with maximum staleness of 15 minutes; and on a full daily reconciliation sweep.

`REQ-RG-014` (MUST) — **Fail closed.** If the check cannot complete and the cached record exceeds 6 hours, ticket creation is refused with a clear, non-punitive message.

`REQ-RG-015` (MUST) — A registry-excluded player with a positive balance has play and deposit blocked, marketing suppressed, and a withdrawal path preserved.

`REQ-RG-016` (MUST) — A registry-excluded player is never asked to self-exclude with Betplus separately, and is never contacted to encourage return.

`REQ-RG-017` (MUST) — The RG service is written for **N registries**, not one.

`REQ-RG-018` (MUST) — Registry sync freshness, cache age and error rate are monitored, alerted, and shown on the Compliance dashboard.

#### 7.9.3 Behaviour and conduct

`REQ-RG-020` (SHOULD) — Behavioural risk flags — rapid stake escalation, deposit-decline chasing, late-night velocity spikes, sustained low-variance play — surface to a review queue for human intervention.

`REQ-RG-021` (MUST) — **Per-game velocity thresholds** are supported in addition to platform-wide limits. BlackRed is fast-cycle, binary and repeatable with no natural pause, which is the profile responsible-gambling frameworks scrutinise hardest, and it warrants tighter thresholds than Heritage.

`REQ-RG-022` (MUST) — No dark patterns. Prohibited: pre-selected maximum stakes, "one more play" pressure prompts, countdown offers on a loss screen, obscured loss framing, celebratory treatment of a losing result, and replay as the visually dominant action on a loss.

### 7.10 Back Office

`REQ-BO-001` (MUST) — RBAC with least privilege. Roles: Support Agent, Support Lead, Finance, Compliance, Game Ops, Content Editor, Cultural Reviewer, System Admin and Super Admin. Super Admin can reach all back-office capabilities but cannot bypass MFA, audit, maker-checker or segregation of duties.

`REQ-BO-002` (MUST) — All actions audit-logged: actor, timestamp, before/after, IP, justification where required.

`REQ-BO-003` (MUST) — Maker-checker required for: prize table publication, game registry changes, catalogue publication, manual payout approval, manual credit or debit, limit override, jurisdiction ruleset changes, tax rate changes, and float top-up recording.

`REQ-BO-004` (MUST) — **Player 360 spanning both games**: profile, KYC status (never raw NIN/BVN), both balances, full ledger, ticket history with outcome trails, payment history, tax deductions, notification history, RG status including registry state.

`REQ-BO-005` (MUST) — Ticket audit tool: given a ticket reference, call the engine's `replay` and display the full deterministic derivation from the sealed seed, for any game.

`REQ-BO-006` (MUST) — No user may alter a settled ticket's outcome. Remediation is by compensating ledger entry with recorded justification.

`REQ-BO-007` (MUST) — Financial reporting sliceable **by game and by state**: stakes, payouts, GGR, RTP actual versus modelled, provider fees, tax lines, float movements.

`REQ-BO-008` (MUST) — Per-state regulatory export in CSV and machine-readable formats, filtered to that state's attributed activity, for arbitrary date ranges.

`REQ-BO-009` (MUST) — **Float console**: current balance, days of cover, burn rate, pending payout value, alert state, top-up history.

`REQ-BO-010` (MUST) — **Jurisdiction console**: per state — licence status and expiry, ruleset version, tax rates, activity volume, remittance status. Operating in a state whose licence has lapsed must be impossible by accident.

`REQ-BO-011` (MUST) — MFA mandatory for all back office accounts; IP allowlisting for privileged roles.

#### 7.10.1 Navigation and surface

`REQ-BO-012` (MUST) — The back office is organised **by operator task, not by database table**. First-level groups are Dashboard · Customers · Finance · Product · Compliance · Insights · Administration. These contain the role-permitted pages Players · Support tickets · Reconciliation · Payouts · Safer play · Licences and tax · Games · Content · Reports · Analytics · Audit log · Team access. Every role lands on a simplified Today at a Glance dashboard.

`REQ-BO-013` (MUST) — Role-based access **removes** destinations a role cannot reach rather than displaying them disabled. An operator's navigation reflects only what they may do.

`REQ-BO-014` (MUST) — The back office is served on a network surface distinct from the player API, with its own authentication guard, IP allowlist and mandatory MFA, so that it can be firewalled independently of the play path.

#### 7.10.2 Maker-checker workflow

`REQ-BO-015` (MUST) — Every change type in `REQ-BO-003` moves through **one shared workflow**: `DRAFT` → `AWAITING_APPROVAL` → (`APPROVED` | `REJECTED`) → `APPLIED`. Adding a new reviewable action is registration against that workflow, never a new bespoke approval path.

`REQ-BO-016` (MUST) — The maker supplies a justification and sees a before/after diff. The checker sees risk context, affected states and games, effective time, and validation results.

`REQ-BO-017` (MUST) — **A user may never approve their own change.** Segregation of duties additionally prevents any individual who configures a prize table from approving a payout (`REQ-SEC-023`).

`REQ-BO-018` (MUST) — Rejection requires a reason and preserves the draft. Applied changes record immutable actor, approver, version and timestamp.

#### 7.10.3 Operational consoles

`REQ-BO-019` (MUST) — Dashboards lead with **actionable exception queues**, not decorative metric cards. The float console leads with balance, days of cover, pending payout value, threshold state and next action. The compliance console leads with licence expiry, registry freshness, failed checks and per-state obligations.

`REQ-BO-020` (MUST) — Every chart carries a text summary, an accessible legend, precise tooltips and access to the underlying table. No operator decision depends on reading a graph alone.

#### 7.10.4 Tables and exports

`REQ-BO-021` (MUST) — Tables default to a scoped date range and never silently load unbounded history. Filters persist in the URL and on return navigation. Financial values are right-aligned, and status uses a label as well as colour.

`REQ-BO-022` (MUST) — Bulk actions display the affected count and require explicit scoped confirmation.

`REQ-BO-023` (MUST) — Exports run asynchronously and deliver a signed, expiring download link rather than streaming synchronously, so that a wide date range cannot occupy a request worker. Every export is audit-logged with actor, filter and row count.

#### 7.10.5 Data handling

`REQ-BO-024` (MUST) — Sensitive identifiers are masked by default in every view. No role sees a raw NIN or BVN except where the separate vault policy explicitly authorises it, and every such access is individually audited (`REQ-ID-023`).

`REQ-BO-025` (MUST) — Every operator action displays the permission it exercises, the reason recorded, and its audit consequence before it is confirmed.

### 7.11 Ticket Lifecycle

`REQ-TKT-001` (MUST) — `POST /v1/tickets` creates a ticket for any game, idempotent on `Idempotency-Key`.

`REQ-TKT-002` (MUST) — Ticket creation performs the following, in two phases:

```
── ELIGIBILITY AND RESOLUTION (no database locks held) ──
1. Resolve state of play; check licence footprint        (fail closed)
2. Check exclusion registry (cached)                     (fail closed)
3. Check RG limits, cool-off, self-exclusion, KYC tier
4. Check game registry: active, channel enabled, state enabled
5. Validate stake against game min/max
6. Obtain seed from Fairness Service
7. Call engine /resolve                                  (idempotent on ticket_id)

── COMMITMENT (single transaction, locks held briefly) ──
8.  Re-assert RG limits and KYC tier                     (see REQ-TKT-012)
9.  Reserve stake from Play Balance into SUSPENSE        (fails if insufficient)
10. Persist ticket, outcome, jurisdiction, seed reference
11. Lock
COMMIT
```

Any failure at any step aborts the whole operation. **No money moves and no ticket exists.**

The engine call sits outside the transaction deliberately. It is a network call, and holding a `FOR UPDATE` lock on a player's wallet row across it converts engine latency directly into database lock contention at exactly the moment throughput matters. Because the engine is a pure function of its inputs (`REQ-GEC-001`) and holds no state (`REQ-GEC-002`), resolving before the transaction is safe by construction: the outcome is a value, not an effect.

`REQ-TKT-011` (MUST) — The engine call is **idempotent on `ticket_id`**, and an outcome whose commitment transaction does not commit is **discarded**. It is never persisted, never settled, never revealed and never counted in any statistical monitoring. A discarded outcome has no observable existence, which is what makes resolving ahead of the transaction equivalent to resolving inside it.

`REQ-TKT-012` (MUST) — Responsible-gambling limits, KYC tier and Play Balance are **re-asserted inside the commitment transaction**, not trusted from the eligibility phase. A concurrent ticket on another channel can consume limit headroom between the two phases; `max_concurrent_tickets` narrows this window but does not close it. The balance check is inherent in the reserve, which fails on insufficient funds; the limit checks are explicit re-reads.

Jurisdiction, licence footprint and exclusion-registry results are **not** re-asserted, being stable over the window and already cached with a bounded staleness under `REQ-RG-013`.

`REQ-TKT-003` (MUST) — States: `CREATED` → `FUNDED` → `IN_PLAY` → `REVEALED` → `SETTLED` → (`PAID` | `SECOND_CHANCE_PENDING` | `CLOSED`), plus terminal `VOIDED` and `REFUNDED`.

`REQ-TKT-004` (MUST) — **The outcome is determined at ticket creation**, before any reveal or animation. The reveal is presentation.

`REQ-TKT-005` (MUST) — The server must not return any unrevealed outcome information before the reveal completes — no unrevealed numbers, no winning set, no tier, and nothing correlated with them including response size and timing within tolerance. This is the most likely exploit vector and is treated as such in review and penetration testing.

`REQ-TKT-006` (MUST) — A ticket in `IN_PLAY` not completed within 24 hours is **auto-settled** server-side on its predetermined outcome. A winning abandoned ticket still pays and still notifies. Abandonment must never be a mechanism for retaining a winning stake.

`REQ-TKT-007` (MUST) — Settlement computes the prize from the ticket's prize table version, applies the Tax Engine, credits the Winnings Balance net, and emits a settlement event.

`REQ-TKT-008` (MUST) — Ticket records are immutable after settlement. Corrections are compensating records only.

`REQ-TKT-009` (MUST) — A player may hold at most one ticket in `IN_PLAY` per game.

`REQ-TKT-010` (MUST) — Game rules and terms state plainly that the outcome is determined at purchase and that the order or choice of reveals does not affect the result.

---

## 8. BlackRed

### 8.1 Concept

BlackRed is an instant binary colour-prediction game. The player predicts the colour of one to five cards in sequence. The machine draws immediately. All predictions must be correct, in exact order, to win.

### 8.2 Core loop

1. Player selects card count (1–5) and predicts a colour for each — `B` or `R`.
2. Player sets a stake.
3. Platform performs the ticket-creation sequence (§7.11).
4. Engine draws the result sequence and resolves the outcome.
5. Settlement: a win credits the Winnings Balance net of tax; a loss forfeits the stake to `HOUSE_REVENUE`.

### 8.3 Engine specification

`REQ-BR-001` (MUST) — Player input is a sequence of 1 to 5 colour predictions, each `B` or `R`, represented as a string (`B`, `BR`, `BRRBB`).

`REQ-BR-002` (MUST) — The draw is a **fair binary event per card position** — each position is independently 50/50. This is a stated design decision: a game presented as "Black or Red" that is not actually even would invite a fairness complaint even when disclosed, so the margin is taken in the multiplier, not in the draw.

`REQ-BR-003` (MUST) — The engine draws a result sequence of equal length from the platform-supplied seed and compares position by position.

`REQ-BR-004` (MUST) — A win requires **every** position to match. One incorrect prediction is a loss. There are no partial prizes.

`REQ-BR-005` (MUST) — Every draw records `rngSeed`, `rngAlgorithm` and `rngOutput` for independent verification.

`REQ-BR-006` (MUST) — The engine is deterministic and replayable per `REQ-GEC-001`.

`REQ-BR-007` (MUST) — Colour distribution per position is monitored per `REQ-RNG-006`. Sustained deviation from 50/50 is a P1 alert.

### 8.4 Prize table

**Decision.** Multipliers are set below fair odds at every tier, with margin increasing as the prize grows. This is the conventional structure, it is transparent, and it keeps every tier comfortably inside the platform ceiling.

Fair odds for *n* cards is 2ⁿ. The published table:

| Cards | Win probability | Fair multiplier | **Betplus multiplier** | Modelled RTP | House edge |
|---|---|---|---|---|---|
| 1 | 1 in 2 | 2× | **1.85×** | 92.5% | 7.5% |
| 2 | 1 in 4 | 4× | **3.60×** | 90.0% | 10.0% |
| 3 | 1 in 8 | 8× | **7.00×** | 87.5% | 12.5% |
| 4 | 1 in 16 | 16× | **13.50×** | 84.4% | 15.6% |
| 5 | 1 in 32 | 32× | **26.00×** | 81.3% | 18.7% |

Example at a ₦1,000 stake: 1 card returns ₦1,850; 3 cards ₦7,000; 5 cards ₦26,000.

`REQ-BR-010` (MUST) — Every tier sits at or below the 95% platform ceiling. The publication gate (`REQ-GEC-025`) enforces this and will refuse any table that does not.

`REQ-BR-011` (MUST) — The table is recorded as versioned configuration and requires actuarial certification before publication. The values above are the approved design target; certification confirms the model, it does not re-open the design.

`REQ-BR-012` (MUST) — Published odds and the game rules page state the true probability of each tier — 1 in 2, 1 in 4, 1 in 8, 1 in 16, 1 in 32 — alongside the multiplier, so the player can see both.

`REQ-BR-013` (MUST) — Blended RTP across the realised stake mix is monitored continuously against the modelled figure and reported to Compliance monthly.

### 8.5 Conduct

`REQ-BR-020` (MUST) — Marketing copy must not encourage escalation from lower to higher card counts. Structured escalation prompts breach `REQ-RG-022`. Presenting the ladder factually is permitted; suggesting the player "go bigger" is not.

`REQ-BR-021` (MUST) — BlackRed is described as fixed-odds prediction. It is not a raffle, lottery or pooled draw, and must not be described as one — the distinction may affect licence categorisation.

`REQ-BR-022` (MUST) — The result screen shows the drawn sequence alongside the player's prediction, position by position, so the outcome is visibly checkable.

---

## 9. Heritage

### 9.1 Concept

Heritage is a culture-themed instant-win game. The player dresses a King or Queen in traditional royal regalia by revealing tiles on a board. Matches dress the figure and determine the prize. A middle outcome earns automatic entry into a 5/90 draw.

### 9.2 The board

`REQ-HG-001` (MUST) — Every Heritage ticket, on every channel, is issued a **board of 9 distinct integers** drawn without replacement from 1–90.

`REQ-HG-002` (MUST) — Web and app render the board as a 3×3 grid of face-down tiles. USSD renders the same nine numbers as a text list. **The underlying board is identical in structure and generation on every channel.** A 5-of-9 pick and a 5-of-90 pick are different games with incomparable odds and cannot share a prize table.

`REQ-HG-003` (MUST) — The player selects exactly 5 of the 9 positions. Fewer, more, or duplicates are rejected.

`REQ-HG-004` (MUST) — Quick Pick selects 5 of the 9 positions uniformly at random, server-side, and confers no different expected value than manual selection.

### 9.3 Outcome determination

`REQ-HG-010` (MUST) — The engine determines the outcome at ticket creation using the platform-supplied seed:

```
1. Draw outcomeTier from the active prize table by weighted selection.
2. Derive matchCount from outcomeTier.
3. Generate the board: 9 distinct integers from 1–90, without replacement.
4. Designate the Winning Set: exactly 5 of the 9 positions, uniformly at random.
5. Return board, winning set, tier and matchCount in engineState.
```

`REQ-HG-011` (MUST) — When the player selects positions, the platform requests a **consistent reveal**: the assignment of board numbers to positions is permuted so that exactly `matchCount` of the player's chosen positions fall inside the Winning Set, preserving the multiset of board numbers. The player's choice does not change the outcome — only which numbers and items appear where.

`REQ-HG-012` (MUST) — Reveal generation is deterministic given `(ticketId, playerSelection)` and replayable byte-identically.

`REQ-HG-013` (MUST) — **At settlement the full board is revealed**, marking all nine positions: picked-and-winning, picked-and-not, unpicked-but-winning, unpicked-and-not. A player told "3 of 5 matched" with no visible basis is being asked to accept an unverifiable assertion about their money.

`REQ-HG-014` (MUST) — Near-miss positions are shown **factually** — no highlight animation, no "so close" copy. Near-misses are a known driver of chasing behaviour.

**Reference combinatorics.** Under the 9-tile board with 5 winners, a true hypergeometric draw gives: 5 matches 0.794%, 4 matches 15.873%, 3 matches 47.619%, 2 matches 31.746%, 1 match 3.968%. Zero matches is impossible — only four non-winning tiles exist. A second-chance entry on every 3-match would therefore occur on 47.6% of plays under a true draw, which is not commercially affordable. The predetermined-outcome model decouples the felt experience from raw combinatorics and allows an approved RTP to be set.

`REQ-HG-015` (MUST) — Player-facing copy must never describe a "0 match" outcome. The lowest tier is **1–2 matches**.

`REQ-HG-016` (SHOULD) — Support a True Draw mode behind a feature flag, where the outcome emerges from the player's actual pick against a pre-drawn winning set, in case a state regulator requires it. Under True Draw the tier probabilities are fixed by combinatorics and are not configurable.

### 9.4 Prize table

**Decision.** Four tiers, with the 4-of-5 outcome defined as a cash prize rather than folded into second chance. A distinct middle cash rung gives the prize ladder shape and is materially cheaper than issuing draw entries on both 3 and 4 matches.

| Tier | Match | Probability | Outcome | Cost per unit stake |
|---|---|---|---|---|
| `TIER_JACKPOT` | 5 of 5 | 0.0200 | **25× stake**, cash | 0.5000 |
| `TIER_HIGH` | 4 of 5 | 0.0600 | **5× stake**, cash | 0.3000 |
| `TIER_SECOND_CHANCE` | 3 of 5 | 0.1600 | 5/90 draw entry at 10% of stake | 0.0160 |
| `TIER_LOSS` | 1–2 of 5 | 0.7600 | No prize | 0 |

Probabilities sum to 1.0000. Modelled RTP **81.6%**, comfortably inside the 95% ceiling.

Player-facing frequency: a jackpot roughly 1 in 50 plays; some prize roughly 1 in 4.2 plays.

`REQ-HG-020` (MUST) — The table is recorded as versioned configuration and requires actuarial certification before publication.

`REQ-HG-021` (MUST) — Modelled RTP is computed and displayed both gross and net of withholding at approval time.

### 9.5 Second chance

**Decision.** A second-chance entry is a **single line lodged at 10% of the player's Heritage stake**. There is no multiplier applied to the entry. A ₦1,000 stake produces a ₦100 line in the next eligible 5/90 draw. If it wins, the draw operator's prize table applies.

This is the cheapest, clearest and most explicable definition. It is easy to state to a player, easy to model, and carries no open-ended liability.

`REQ-HG-030` (MUST) — On `TIER_SECOND_CHANCE` settlement, the player's five selected numbers are entered as a single line into the next eligible 5/90 draw operated by a contracted licensed draw operator, at a stake of `sc_stake_ratio` × player stake (default 0.10).

`REQ-HG-031` (MUST) — The Draw Partner Bridge is written against an internal interface — `submitEntry`, `getReceipt`, `getDrawCalendar`, `getResults`, `reconcile` — with a per-partner adapter. Switching or adding a partner must not touch the Heritage engine.

`REQ-HG-032` (MUST) — Draw eligibility respects a 20-minute cut-off before each draw. Nigerian 5/90 operators run draws several times daily, so the cut-off is deliberately tight. Entries settled inside the cut-off roll to the next draw and the player is told which draw they are in.

`REQ-HG-033` (MUST) — The draw calendar is configuration, ingested or maintained in back office. It must handle **multiple draws per day** and same-day schedule shifts. Draw names and times are never hardcoded.

`REQ-HG-034` (MUST) — Submission is asynchronous, durable and retried. Settlement is never blocked on partner availability; the entry queues in `SECOND_CHANCE_PENDING`.

`REQ-HG-035` (MUST) — Each successful submission returns a partner ticket reference, persisted and surfaced to the player. **An entry without a verifiable partner reference must not be presented to the player as lodged.**

`REQ-HG-036` (MUST) — A digital receipt is delivered by SMS within 5 minutes of confirmed lodgement: the five numbers, draw name and time, partner reference, Betplus ticket reference.

`REQ-HG-037` (MUST) — If an entry cannot be lodged before cut-off it rolls automatically and the player is notified. If it cannot be lodged within three draws, the player is credited with the value of the entry and told plainly.

`REQ-HG-038` (MUST) — Every second-chance player receives a result notification whether they won or not. This is the retention loop and a trust signal.

`REQ-HG-039` (MUST) — Daily reconciliation between submitted entries and partner-confirmed entries. Any record without a counterpart is a P1 exception.

`REQ-HG-040` (MUST) — Circuit breaker on sustained partner failure: continue accepting plays, queue entries, alert Ops, and beyond a threshold switch player messaging to set correct expectations.

`REQ-HG-041` (SHOULD) — Support more than one contracted draw partner with per-entry routing. Several licensed 5/90 operators exist and single-partner dependency is avoidable business risk.

### 9.6 Royal tradition and leader

`REQ-HG-050` (MUST) — The player selects a royal tradition and a leader type (King or Queen), stored on the profile and snapshotted onto each ticket.

`REQ-HG-051` (MUST) — Tradition and leader are **cosmetic only**. They must not affect board generation, outcome probabilities or prize values. Asserted by automated test and stated in the game rules.

`REQ-HG-052` (MUST) — Tradition is **distinct from state of play** and the two must never be conflated. A Yoruba-tradition player may be physically in Kano; tradition drives visuals, state drives licensing and tax.

`REQ-HG-053` (MUST) — Any suggested default must not be derived from ethnicity inference. Traditions are presented equally.

`REQ-HG-054` (MUST) — Traditions are enumerated in configuration, not hardcoded.

Launch traditions:

| Tradition | Leader titles |
|---|---|
| Yoruba | Ọba / Olorì |
| Igbo | Eze / Lolo |
| Hausa–Fulani | Sarki / Sarauniya |
| Edo (Benin) | Ọba / Iyọba |
| Efik–Ibibio | Obong / Ọbọñ an Iban |
| Ijaw | Amanyanabo |
| Middle Belt (Nupe, Kanuri, Tiv) | Etsu / Shehu / Tor |

### 9.7 Regalia catalogue

**Decision on coherence.** The **royal figure is tradition-specific**; the **90 regalia items are pan-Nigerian**, each labelled with its tradition of origin. The player's chosen tradition determines the silhouette and its base dress; revealed items are drawn from the whole catalogue and are shown with their origin ("Ìrùkẹ̀rẹ̀ — Yoruba").

Player-facing copy is honest to this: *"Dress your royal in regalia from across Nigeria."*

Rationale: it costs 14 base figures plus 90 items rather than 90 items × 7 traditions × 2 leaders, it ships without a per-tradition art multiplier, it teaches the player something on every reveal, and it never claims an item belongs to a tradition it does not. Per-tradition item variants remain available as a v2 enhancement.

`REQ-HG-060` (MUST) — Exactly 90 catalogue items exist, numbered 1–90, with a **stable, immutable, one-to-one** number→item mapping. Renumbering after launch is prohibited, and back office refuses any operation that changes which number maps to which item after the first live ticket.

`REQ-HG-061` (MUST) — The mapping is generated once, stored in the catalogue table, and read at reveal time. It is never generated per round. A per-round mapping would make collection impossible, prevent audit replay from reconstructing a historical ticket, and mean two players holding the same number saw different items.

`REQ-HG-062` (MUST) — Each item carries: number, canonical name, local-language name, tradition of origin, leader applicability, body slot, layer priority, cultural description, advisory sign-off reference, and depiction constraint.

`REQ-HG-063` (MUST) — Body slots are head, neck, torso, waist, wrist, hand and feet, each with anchor point, scale, offset and z-order. If two revealed items occupy the same slot, higher layer priority renders on top; both remain in the revealed set and both count toward matching. **Matching is number-based and never affected by rendering.**

`REQ-HG-064` (MUST) — **Cultural review is a launch gate.** All names, descriptions and imagery are reviewed and signed off by a named cultural advisor per tradition before publication. An item without a sign-off reference cannot be published.

This carries more weight than a generic content review. The Yoruba Adé is not decorative — beads are treated as sacred, the right to wear a beaded crown is restricted by lineage, and the veil exists to obscure the king's face for spiritual reasons. Benin coral regalia carries comparable significance. Rendering these as prizes in a gambling game is capable of causing real offence to real palaces, and no post-launch apology undoes it. The review must specifically address which items may be depicted at all, whether any require abstraction rather than realistic depiction, and whether the dressing animation is acceptable for sacred items.

`REQ-HG-065` (MUST) — The catalogue must not depict any identifiable living monarch, any specific existing palace's regalia, or any item the advisory review flags as restricted.

`REQ-HG-066` (MUST) — Diacritics in Yoruba and Igbo item names render correctly across Web, app and SMS. Where SMS encoding cannot carry them, a documented transliteration is used rather than mangled output.

`REQ-HG-067` (MUST) — Assets are illustrated vector or vector-composited, not photographic. Photographic garments do not composite onto a vector figure, and illustration is cheaper to keep consistent across 90 items.

`REQ-HG-068` (MUST) — Each item has a low-bandwidth variant served when the client reports a slow connection.

`REQ-HG-069` (SHOULD) — Collection view showing which of the 90 items a player has revealed.

### 9.8 Presentation

`REQ-HG-070` (MUST) — A running match counter is displayed **during** play, not only at the end. "Two matched, two picks left" is the tension of the game.

`REQ-HG-071` (MUST) — On reveal, each item shows its name, tradition of origin, and one line of cultural context. This is the heritage differentiator and the cheapest high-value element in the product: it costs writing, not art.

`REQ-HG-072` (MUST) — The empty-state figure shows ghosted slot outlines that fill as items land, so the player understands the system without a tutorial.

`REQ-HG-073` (MUST) — Reveal outcomes are conveyed by text as well as animation.

`REQ-HG-074` (MUST) — A `prefers-reduced-motion` path skips animation and shows results directly.

`REQ-HG-075` (MUST) — **No control may exist that implies the outcome can be re-rolled.** A "restart round" affordance is prohibited: either it genuinely rerolls, which makes RTP unbounded, or it consumes the stake without settling, which takes money for nothing. The permitted controls are Cancel before funding, Quick Pick remaining during play, and Play Again after settlement.

---

## 10. Channels

### 10.1 Web

`REQ-WEB-001` (MUST) — Responsive, mobile-first. The two-column desktop layout is the adaptation, not the baseline.

`REQ-WEB-002` (MUST) — First contentful paint on 3G ≤ 2.5 s; first-play asset payload ≤ 2.5 MB.

`REQ-WEB-003` (MUST) — **WCAG 2.2 AA**: contrast, focus order and appearance, screen-reader labels and status announcements, no colour-only information, minimum 44×44 CSS px targets, reflow at 320 CSS px, and zoom to 400% without clipping.

2.2 rather than 2.1, aligned to the UX Design Standard §12. The additions that matter here are focus appearance and target size, both of which the interface needs regardless: BlackRed rests entirely on a red/black distinction, and every channel is used one-handed on a phone.

`REQ-WEB-004` (MUST) — CSP, HSTS and `X-Frame-Options` headers set at Nginx.

### 10.2 Mobile app (React Native)

`REQ-APP-001` (MUST) — A single React Native codebase targets **Android and iOS**.

`REQ-APP-002` (MUST) — **Hermes** JavaScript engine enabled on both platforms, with ProGuard/R8 and ABI splits on Android. Target Android download size ≤ 25 MB.

`REQ-APP-003` (MUST) — Native modules are required for certificate pinning, root and jailbreak detection, mock-location detection, and secure storage. These cannot be implemented in JavaScript and must be planned as native work.

`REQ-APP-004` (MUST) — No sensitive data in AsyncStorage. Credentials and tokens use Keychain (iOS) and Keystore (Android) via a secure-storage native module.

`REQ-APP-005` (MUST) — **Over-the-air JavaScript updates are disabled for any release affecting game presentation, money handling, responsible gambling or compliance surfaces.** Game logic is server-side, so OTA carries no functional benefit that justifies bypassing store review for a regulated product.

`REQ-APP-006` (MUST) — **iOS store requirements.** Real-money gaming apps must be submitted by the licensed entity or its authorised representative, must be geo-restricted to territories where the operator is licensed, must be free to download, and must not process wagers through in-app purchase. The Betplus iOS app satisfies all four: submitted by the licensed entity, geo-gated by the same attribution service used for play, free, and funded through OPay rather than IAP.

`REQ-APP-007` (MUST) — **Android store requirements.** Google Play requires a separate application and approval for real-money gambling apps, restricted to supported countries, with age-gating and responsible-gambling information in the listing. Approval is obtained before store submission, and the outcome for Nigeria is confirmed during Phase 0.

`REQ-APP-008` (MUST) — The app degrades to a webview-free, native-rendered offline state if the platform is unreachable, showing balance last known and a clear "cannot play offline" message. It never simulates a game locally.

`REQ-APP-009` (MUST) — Location permission is requested with a clear explanation tied to licensing, not buried. Denial routes to a lower-confidence attribution path and, if that fails, to a refusal with an explanation.

`REQ-APP-010` (MUST) — Heritage reveal animation holds ≥ 30 fps on a 2 GB RAM Android 10 device. React Native animations use the native driver.

### 10.3 USSD

`REQ-USSD-001` (MUST) — Support across MTN, Airtel, Glo and 9mobile via a contracted aggregator, on a single shortcode shared by both games.

`REQ-USSD-002` (MUST) — Every screen fits within **160 characters** including the prompt. Validated by automated test; the build fails on overflow, in every supported locale.

`REQ-USSD-003` (MUST) — Session state is held server-side in Redis, keyed `(msisdn, sessionId)`, with row-level locking per turn and a 7-minute application-side window. Background cleanup purges expired sessions every 5 minutes.

`REQ-USSD-004` (MUST) — Sessions are resumable within 10 minutes: redialling offers "Continue your last play".

`REQ-USSD-005` (MUST) — A funded ticket is never lost to a dropped session. It auto-settles and the result is delivered by SMS.

`REQ-USSD-006` (MUST) — Invalid input returns the same screen with a one-line error prefix. Never a dead end, never a silent drop.

`REQ-USSD-007` (MUST) — p95 response per screen ≤ 2 s.

`REQ-USSD-008` (MUST) — A returning player reaches a funded ticket in **≤ 5 screens** from dial.

#### Registration flow

```
[Dial *XXX#]

S1  Welcome to Betplus!
    Play. Win. Get paid.
    1. Register
    2. How to play
    3. Terms
    (18+ only)

S2  We found your OPay account:
    ADEBAYO OLUWASEUN
    1. Yes, that's me
    2. No

S3  Date of birth DDMMYYYY
    (18+ only):

S4  Enter your NIN (11 digits).
    Required by law and to protect
    players who have self-excluded.

S5  Welcome, Adebayo!
    Balance: N0
    1. Fund account
    2. Play
```

#### Returning player

```
S1  Betplus  ·  Bal N2,400
    1. Play BlackRed
    2. Play Heritage
    3. Fund account
    4. My account
    5. Help

--- BlackRed ---
S2  How many cards? (1-5)
S3  Enter colours, B or R
    e.g. BRB for 3 cards:
S4  Stake (N100 - N20,000):
S5  Confirm BRB · N1,000
    Win N7,000 if all correct
    1. Confirm  2. Cancel
S6  RESULT
    Draw: B R B
    You:  B R B
    YOU WIN N7,000
    Less 5% tax N300
    Paid N6,700 to your OPay.

--- Heritage ---
S2  Style: 1.Yoruba 2.Igbo
    3.Hausa 4.Benin 5.Efik
    6.Ijaw 7.Middle Belt
S3  Dress a: 1.King 2.Queen
S4  Stake (N100 - N20,000):
S5  Your board:
    1)07 2)14 3)23 4)31 5)44
    6)52 7)60 8)71 9)88
    Pick 5 or 0 = Quick Pick
S6  Confirm N1,000 · 07 23 44 60 88
    1. Confirm  2. Cancel
S7  RESULT  3 of 5 matched
    Winners: 07 23 44 52 71
    You are in the 2:00pm
    5/90 draw. Ticket by SMS.
```

The result screens show the drawn sequence (BlackRed) and the winning set (Heritage), so the player can check the claim.

#### Funding

```
F1  Fund account
    Amount (N100 - N50,000):
F2  Sending OTP to your phone…
F3  Enter the OTP sent to you:
F4  Funded! Balance: N3,400
    1. Play   0. Main menu
```

`REQ-USSD-010` (MUST) — In-session funding uses OPay Bank Account + OTP, with `bankAccountNumber` derived from the MSISDN.

`REQ-USSD-011` (MUST) — If the collection does not resolve within 25 seconds, the session closes with "We'll SMS you when it lands" and processing continues asynchronously. The player is never left holding an open session waiting on a provider.

`REQ-USSD-012` (MUST) — On insufficient balance the flow offers funding inline; it never simply refuses.

`REQ-USSD-013` (MUST) — A player without completed Tier 1 verification is routed to verification rather than failing opaquely.

#### Account and responsible gambling menus

```
My account                Take a break
  1. Balances               1. 24 hours
  2. Last 5 plays           2. 7 days
  3. Withdraw               3. 30 days
  4. My draw tickets        4. Self-exclude (6 months)
  5. Set limits             5. About state exclusion
  6. Take a break           0. Back
  0. Back
```

`REQ-USSD-020` (MUST) — "Set limits" and "Take a break" are reachable within two screens of the main menu.

`REQ-USSD-021` (MUST) — "About state exclusion" explains that a state-level exclusion covers all licensed operators and gives the enrolment route. It must not discourage enrolment.

---

## 11. Data Model

### 11.1 Layer view

```
┌───────────────────────────────────────────────────────────────────────┐
│ 1. IDENTITY                                                           │
│   player (id, msisdn, registeredName, kycTier, ninHash,               │
│           bvnVerifiedAt, residencyStatus, dateOfBirth, status,        │
│           createdAt, deletedAt)                                       │
│   identityVault (playerId, ninEnc, bvnEnc)          ← separate ACL    │
│   kycRecord (playerId, method, verificationRef, verifiedAt)           │
│   playerSession · institutionUser · institutionRole                   │
└───────────────────────────────┬───────────────────────────────────────┘
┌───────────────────────────────▼───────────────────────────────────────┐
│ 2. WALLET & LEDGER                    (Wallet Service — sole writer)  │
│   account (accountCode, accountType)                                  │
│   wallet (playerId, walletType, accountId, cachedBalanceKobo, version) │
│   walletTransaction (refNumber, txnType, amountKobo, status)          │
│   ledgerEntry (walletTxnId, accountId, side, amountKobo, stateCode)   │
│   depositTurnover (depositId, amountKobo, turnedOverKobo, releasedAt) │
└───────────────────────────────┬───────────────────────────────────────┘
┌───────────────────────────────▼───────────────────────────────────────┐
│ 3. PAYMENTS                                                           │
│   depositRequest (refNumber, playerId, amountKobo, payMethod,         │
│                   opayOrderNo, nextAction, status)                    │
│   withdrawalRequest (refNumber, sourceWallet, turnoverMet, status)    │
│   payoutOrder (merchantOrderNo, opayOrderNo, amountKobo, mdrKobo,     │
│                status, attempts, lastError)                           │
│   floatSnapshot (balanceKobo, capturedAt, alertLevel, coverDays)      │
│   floatTopUp (amountKobo, recordedBy, approvedBy, reference)          │
└───────────────────────────────┬───────────────────────────────────────┘
┌───────────────────────────────▼───────────────────────────────────────┐
│ 4. GAMES                                                              │
│   game (gameCode, engineEndpoint, engineVersion, status,              │
│         minStakeKobo, maxStakeKobo, enabledChannels, enabledStates)   │
│   prizeTable (gameCode, version, effectiveFrom, certificationRef,     │
│               modelledRtp, approvedBy, approvedAt)                    │
│   prizeTier (prizeTableVersion, tierCode, probability, payoutType,    │
│              multiplier)                                              │
│   ticket (ticketRef, playerId, gameCode, channel, stakeKobo,          │
│           prizeTableVersion, engineVersion, state, idempotencyKey)    │
│   ticketOutcome (ticketId, outcomeTier, grossPrizeKobo,               │
│                  netPrizeKobo, engineState JSON, rngSeedRef, digest)  │
│                                                     ← write-once      │
│   ticketJurisdiction (ticketId, stateCode, confidence, primarySignal, │
│                       signalsUsed JSON, rulesetVersion)               │
│                                                     ← write-once      │
│   taxDeduction (ticketId, taxType, stateCode, basisKobo,              │
│                 basisDefinition, rate, amountKobo, remittedAt)        │
│   ── BlackRed ──                                                      │
│   blackredDraw (ticketId, cardCount, playerSelection, drawSelection,  │
│                 rngSeed, rngOutput)                                   │
│   ── Heritage ──                                                      │
│   heritageReveal (ticketId, sequence, position, number, itemNumber,   │
│                   isMatch)                                            │
│   catalogueItem (itemNumber PK 1–90, canonicalName, localName,        │
│                  traditionOrigin, bodySlot, layerPriority,            │
│                  culturalDescription, advisorySignoffRef,             │
│                  depictionConstraint)                                 │
│   secondChanceEntry (ticketId, numbers, drawPartnerCode, targetDrawId,│
│                      partnerTicketRef, status, rollCount)             │
└───────────────────────────────┬───────────────────────────────────────┘
┌───────────────────────────────▼───────────────────────────────────────┐
│ 5. COMPLIANCE, INTEGRATION & AUDIT                                    │
│   jurisdictionRuleset (stateCode, version, licenceStatus, expiry,     │
│                        whtRateResident, whtRateNonResident,           │
│                        whtBasisDefinition, ggrLevyRate,               │
│                        rgRegistryCode, effectiveFrom)                 │
│   registryStatus (playerId, registryCode, isExcluded, exclusionUntil, │
│                   lastCheckedAt, lastCheckStatus)   ← cache           │
│   rgRestriction (playerId, type, value, effectiveFrom, effectiveTo)   │
│   opayApiCallLog (callType, endpoint, requestRef, outcome, latencyMs) │
│   smsLog (msisdn, templateVersion, providerRef, status)               │
│   suspiciousActivityFlag (playerId, reason, severity, reviewedBy)     │
│   rngSeedRecord (seedRef, sealedSeed, prevHash, createdAt)  ← WORM    │
│   auditLog (actor, action, before, after, ip, justification)          │
└───────────────────────────────────────────────────────────────────────┘
```

### 11.2 Retention and privacy

`REQ-DATA-001` (MUST) — Transactional and gaming records retained **seven years** from last transaction. Consent records for account life plus two years. Raw analytics 25 months. Fine-grained location signals only as long as needed to resolve and evidence attribution.

`REQ-DATA-002` (MUST) — PII encrypted at rest, with column-level encryption for NIN, BVN, date of birth and name.

`REQ-DATA-003` (MUST) — Non-production environments use masked or synthetic data. Production PII in a test environment is a reportable incident.

`REQ-DATA-004` (MUST) — Data subject rights supported by tooling, not ad-hoc SQL.

---

## 12. APIs

### 12.1 Conventions

Versioned under `/v1`. JSON over HTTPS. Bearer auth. Mutating requests carry `Idempotency-Key`. Errors follow RFC 7807 with a stable `code`. `X-Request-Id` on every response. All amounts integer kobo with explicit `NGN`.

### 12.2 Ticket creation

```http
POST /v1/tickets
Idempotency-Key: <uuid>

{
  "game_code": "BLACKRED",
  "stake_kobo": 100000,
  "channel": "APP_IOS",
  "player_input": { "predictions": "BRB" },
  "location": { "lat": 6.5244, "lng": 3.3792, "accuracy_m": 25, "source": "GPS" }
}
```

```json
{
  "ticket_id": "…",
  "ticket_ref": "BZC-7K4M-2Q9X",
  "game_code": "BLACKRED",
  "state": "SETTLED",
  "stake_kobo": 100000,
  "jurisdiction": { "state_code": "LA", "confidence": 0.97 },
  "settlement": {
    "outcome_tier": "TIER_WIN_3",
    "gross_prize_kobo": 700000,
    "tax": {
      "type": "WHT_WINNINGS", "rate": 0.05,
      "basis_kobo": 600000, "basis_definition": "NET_WINNINGS",
      "amount_kobo": 30000, "remit_to": "LA"
    },
    "net_prize_kobo": 670000,
    "payout_status": "DISPATCHED"
  },
  "engine_state": { "player_selection": "BRB", "draw": "BRB" }
}
```

Heritage tickets return `state: IN_PLAY` and require reveals:

```http
POST /v1/tickets/{id}/reveal      { "position": 3 }
POST /v1/tickets/{id}/quickpick
```

The reveal response returns the number, the catalogue item with its tradition and cultural description, and `reveals_remaining`. **No unrevealed information appears until the fifth reveal**, at which point the full board and settlement block are returned.

### 12.3 Player endpoints

```http
GET  /v1/games                                # per channel and state
GET  /v1/players/me/tickets?game=…&cursor=…   # unified across games
GET  /v1/wallet                               # both balances + turnover progress
POST /v1/wallet/deposits                      { "amount_kobo": 500000, "method": "BANK_ACCOUNT" }
POST /v1/wallet/deposits/{id}/otp             { "otp": "123456" }
POST /v1/wallet/withdrawals                   { "amount_kobo": 200000, "source": "WINNINGS" }
POST /v1/wallet/transfer                      { "amount_kobo": 100000 }   # winnings → play
GET  /v1/wallet/transactions?cursor=…
GET  /v1/heritage/catalogue  ·  /v1/heritage/collection
GET  /v1/second-chance/entries
GET  /v1/rg/limits           ·  PUT /v1/rg/limits
POST /v1/rg/cool-off         ·  POST /v1/rg/self-exclusion
GET  /v1/rg/net-position?period=P30D
GET  /v1/tax/statements?year=2026
```

### 12.4 Internal endpoints

| Endpoint | Purpose |
|---|---|
| `POST /internal/opay/callback/payin` | Collection callback — HMAC-SHA512 verified |
| `POST /internal/opay/callback/payout` | Payout callback — signature verified, **amount in Naira** |
| `POST /internal/ussd/session` | USSD gateway turn — signature verified |
| `POST /internal/draw/results/{partner}` | 5/90 draw result ingestion |
| `POST /internal/registry/sync/{registry}` | Exclusion registry sync |
| `GET  /internal/health/live` · `/ready` | Liveness / readiness |

### 12.5 Wallet Service API

Called by the platform only. Engines never call it.

```http
POST /wallet/v1/reserve   { player_id, amount_kobo, ref_number, purpose }
POST /wallet/v1/capture   { ref_number }
POST /wallet/v1/release   { ref_number }
POST /wallet/v1/credit    { player_id, balance: "WINNINGS", amount_kobo, ref_number }
GET  /wallet/v1/balance/{player_id}
GET  /wallet/v1/turnover/{player_id}
```

### 12.6 OPay endpoints

| Function | Endpoint | Auth |
|---|---|---|
| Wallet name lookup | `/api/v1/international/payout/opay-wallet-validate` | RSA-SHA256 |
| Bank account validate | `/api/v1/international/payout/bank-account-validate` | RSA-SHA256 |
| Collection create | `/api/v1/international/payment/create` | HMAC-SHA512 |
| Submit OTP / PIN | `/api/v1/international/payment/input-otp` · `input-pin` | HMAC-SHA512 |
| Collection status | `/api/v1/international/payment/status` | HMAC-SHA512 |
| Payout create | `/api/v1/international/payout/createSingleOrder` | RSA-SHA256 |
| Payout status | `/api/v1/international/payout/queryorder` | RSA-SHA256 |
| Float balance | `/api/v1/international/payout/balance` | RSA-SHA256 |
| Bank list | `/api/v1/international/banks` | RSA-SHA256 |

Environments: `https://testapi.opaycheckout.com` and `https://liveapi.opaycheckout.com`.

### 12.7 Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `STAKE_OUT_OF_RANGE` | 400 | Outside the game's configured range |
| `GAME_NOT_AVAILABLE` | 403 | Suspended, or not enabled for this channel or state |
| `INSUFFICIENT_PLAY_BALANCE` | 402 | Play Balance below stake |
| `TURNOVER_NOT_MET` | 403 | Play Balance withdrawal before 1× turnover |
| `RG_LIMIT_EXCEEDED` | 403 | Deposit or stake limit reached |
| `SELF_EXCLUDED` | 403 | Excluded by Betplus |
| `REGISTRY_EXCLUDED` | 403 | Excluded by a state registry |
| `REGISTRY_UNAVAILABLE` | 503 | Exclusion check could not complete — fail closed |
| `STATE_NOT_LICENSED` | 403 | Attributed state outside licence footprint |
| `LOCATION_UNVERIFIED` | 422 | Attribution confidence below threshold |
| `LOCATION_SPOOFING_DETECTED` | 403 | VPN, proxy or mock location |
| `KYC_TIER_REQUIRED` | 403 | Commonly: NIN not verified |
| `NO_OPAY_WALLET` | 422 | MSISDN has no OPay wallet |
| `TICKET_ALREADY_SETTLED` | 409 | |
| `POSITION_ALREADY_REVEALED` | 409 | Heritage |
| `PAYMENT_PENDING` | 409 | Ticket not funded |
| `FLOAT_INSUFFICIENT` | 503 | Payout queued; winnings retained in balance |
| `PROVIDER_UNAVAILABLE` | 503 | OPay degraded |

---

## 13. Non-Functional Requirements

### 13.1 Performance

Targets are set for the launch topology — a single tuned application server — with the scale path in `REQ-HOST-008` available before they are approached.

| ID | Requirement | Target |
|---|---|---|
| `REQ-NFR-001` | API p95 latency, play path | ≤ 400 ms |
| `REQ-NFR-002` | API p99 latency, play path | ≤ 900 ms |
| `REQ-NFR-003` | USSD screen response p95 | ≤ 2 s |
| `REQ-NFR-004` | Engine `resolve` p95 | ≤ 80 ms |
| `REQ-NFR-005` | Geo attribution added latency p95 | ≤ 120 ms |
| `REQ-NFR-006` | Registry check (cached) added latency p95 | ≤ 30 ms |
| `REQ-NFR-007` | Sustained throughput, launch topology | **300 tickets/s** |
| `REQ-NFR-008` | Peak burst, launch topology | **800 tickets/s for 10 min** |
| `REQ-NFR-009` | Sustained throughput, scaled topology | 1,500 tickets/s |
| `REQ-NFR-010` | Web first contentful paint on 3G | ≤ 2.5 s |
| `REQ-NFR-011` | Heritage first-play asset payload | ≤ 2.5 MB |
| `REQ-NFR-012` | Android app download size | ≤ 25 MB |
| `REQ-NFR-013` | Heritage reveal animation, 2 GB Android 10 | ≥ 30 fps |

`REQ-NFR-014` (MUST) — Database transactions hold locks for minimum duration using narrow `FOR UPDATE` scopes.

`REQ-NFR-015` (MUST) — Load testing is run against the actual cPanel topology, not a container approximation, before each major release.

`REQ-NFR-016` (MUST) — **Scale-out trigger.** When observed sustained peak exceeds **60% of the load-tested launch capacity** over any rolling 7-day window, the `REQ-HOST-008` scale-out is executed. The trigger is monitored and alerted like a float threshold (`REQ-NFR-043`), not reviewed at someone's discretion.

Capacity tiers without a trigger are the common failure: the numbers are agreed, the growth is gradual, and the migration happens under load instead of ahead of it. 60% leaves headroom for the burst target while the scale-out is rehearsed and executed.

`REQ-NFR-017` (MUST) — The single application server hosts Nginx, three PHP-FPM pools, the Python worker processes, MariaDB, Redis, queue workers and monitoring. **Per-service resource limits are set explicitly** so that no component can starve the play path — in particular, reporting queries, export jobs and analytics rollups run at lower priority and are capped, per `REQ-BO-023`.

### 13.2 Availability and resilience

| ID | Requirement |
|---|---|
| `REQ-NFR-020` | Play path monthly uptime ≥ 99.9% |
| `REQ-NFR-021` | RPO ≤ 1 minute; RTO ≤ 30 minutes |
| `REQ-NFR-022` | Documented and tested restore runbook; monthly restore drill |
| `REQ-NFR-023` | Graceful degradation: Draw Partner, Notifications and Analytics outages do not block play |
| `REQ-NFR-024` | **Fail-closed dependencies** — Geo and registry checks block play when unsatisfiable, so their availability target equals the play path's and they require redundancy |
| `REQ-NFR-025` | **Engine isolation** — one engine failing must not affect the other game. Circuit breaker per engine; a failed engine suspends only its own game. A Python worker exhaustion must not take BlackRed offline, and PHP-FPM saturation must not take Heritage offline |
| `REQ-NFR-026` | Circuit breakers on every external dependency |
| `REQ-NFR-027` | Nginx returns a maintained, branded error page rather than a default 502 when an upstream is down |

### 13.3 Scalability

| ID | Requirement |
|---|---|
| `REQ-NFR-030` | Application layer is stateless; state lives in Redis and MariaDB |
| `REQ-NFR-031` | Read replica for reporting before launch; reporting never touches the primary |
| `REQ-NFR-032` | `ticket`, `ledgerEntry` and `taxDeduction` partitioned by month; ledger additionally indexed by `stateCode` |
| `REQ-NFR-033` | Python engine deployed as multiple worker processes, not threads, and load-tested |
| `REQ-NFR-034` | PHP-FPM pool sizing, `opcache`, MariaDB buffer pool and Redis `maxmemory` tuned and documented |

### 13.4 Observability

| ID | Requirement |
|---|---|
| `REQ-NFR-040` | Request tracing with a correlation ID propagated across Nginx, PHP, the Python engine and the USSD adapter |
| `REQ-NFR-041` | Structured JSON logs, PII redacted. **NIN and BVN never logged** |
| `REQ-NFR-042` | Business dashboards: stake volume by game, RTP actual vs modelled by game, payout latency, USSD funnel, per-state activity and tax liability, float cover |
| `REQ-NFR-043` | Alerting on: RTP deviation per game, payout failure rate, **float thresholds**, OPay error rate, draw queue depth, reconciliation exceptions, USSD timeout rate, geo attribution failure rate, registry sync staleness, licence expiry within 60 days, disk and memory headroom |
| `REQ-NFR-044` | Defined on-call rotation with documented P1/P2 SLAs |
| `REQ-NFR-045` | Log rotation and off-server shipping — cPanel disk exhaustion from logs is a foreseeable outage cause |

### 13.5 Accessibility and localisation

| ID | Requirement |
|---|---|
| `REQ-NFR-050` | Web and app meet **WCAG 2.2 AA**, including focus appearance and 44×44 CSS px / 48×48 dp target size |
| `REQ-NFR-051` | Outcomes conveyed by text as well as animation |
| `REQ-NFR-052` | All strings externalised. English and Nigerian Pidgin at launch |
| `REQ-NFR-053` | Currency, date and number formatting per Nigeria locale (₦, WAT) |
| `REQ-NFR-054` | `prefers-reduced-motion` path |
| `REQ-NFR-055` | Yoruba and Igbo diacritics render correctly across Web, app and SMS, with documented transliteration fallback |

---

## 14. Security

### 14.1 Application

`REQ-SEC-001` (MUST) — Threat model documented before build, covering: outcome prediction, reveal-response tampering, replay of payment callbacks, USSD MSISDN spoofing, location spoofing, NIN/BVN harvesting, float manipulation, account takeover, and insider prize manipulation.

`REQ-SEC-002` (MUST) — Independent penetration test before launch and annually. All Critical and High findings closed before go-live.

`REQ-SEC-003` (MUST) — Input validation at every boundary; PDO prepared statements throughout; output encoding; security headers at Nginx.

`REQ-SEC-004` (MUST) — Rate limiting per IP, per MSISDN and per device on OTP, login, ticket creation and withdrawal. Nginx `limit_req` provides the first layer; application-level limits provide the second.

`REQ-SEC-005` (MUST) — Secrets in a managed store outside the web root. **RSA private keys for OPay payouts never in source control and never inside `public_html`.**

`REQ-SEC-006` (MUST) — SAST and dependency scanning in CI for both PHP and Python toolchains; builds fail on Critical vulnerabilities.

`REQ-SEC-007` (MUST) — Passwords and transaction PINs hashed with Argon2id or bcrypt.

`REQ-SEC-008` (MUST) — Mobile app: certificate pinning, root and jailbreak detection, mock-location detection, secure storage, release-build obfuscation.

`REQ-SEC-009` (MUST) — USSD gateway requests authenticated by signature and source-IP allowlist.

`REQ-SEC-010` (MUST) — NIN and BVN vault-isolated, tokenised for application use, individually audited, never returned in full, never exported to analytics.

`REQ-SEC-011` (MUST) — **Engine endpoints bind to loopback and are not routable from the internet.** An engine callable directly with an arbitrary seed would allow outcome enumeration.

`REQ-SEC-012` (MUST) — Application code lives outside `public_html`; only a thin front controller is web-exposed. Directory listing disabled. `.git`, `.env`, backup files and dotfiles blocked at Nginx.

### 14.2 Fraud, abuse and insider risk

`REQ-SEC-020` (MUST) — Velocity rules on registration, deposit, play and withdrawal, aggregated across games.

`REQ-SEC-021` (MUST) — Multi-accounting detection: device fingerprint, NIN reuse, behavioural clustering.

`REQ-SEC-022` (MUST) — AML monitoring per `BP-AML-001` §9, including low-variance concentration and turnover-to-deposit ratio — the two signatures that catch laundering irrespective of profitability.

`REQ-SEC-023` (MUST) — Segregation of duties: no individual may both configure a prize table and approve a payout.

`REQ-SEC-024` (MUST) — Maker-checker with real-time Compliance alerting on prize table changes, game registry changes, tax rate changes, jurisdiction ruleset changes, manual credits and float top-up recording.

`REQ-SEC-025` (MUST) — Threshold reporting to the NFIU via goAML: single transaction above ₦5,000,000 (individual) or ₦10,000,000 (body corporate), within seven days. Suspicious Transaction Reports immediately on suspicion, no de minimis. Tipping off prohibited.

### 14.3 Infrastructure

`REQ-SEC-030` (MUST) — MariaDB and Redis bind to localhost or a private interface only; no public database endpoints. cPanel remote MySQL access disabled.
`REQ-SEC-031` (MUST) — WHM and cPanel administrative interfaces IP-restricted and behind MFA.
`REQ-SEC-032` (MUST) — SSH key-only authentication; password authentication and root login disabled.
`REQ-SEC-033` (MUST) — WAF and DDoS protection in front of the origin, with the origin IP not publicly resolvable where possible.
`REQ-SEC-034` (MUST) — Encryption at rest and in transit (TLS 1.2+, modern cipher suites at Nginx).
`REQ-SEC-035` (MUST) — Automated OS, cPanel, PHP and Python security patching with a documented window.
`REQ-SEC-036` (MUST) — Centralised tamper-evident audit logging shipped off-server, 12-month hot retention minimum.
`REQ-SEC-037` (MUST) — Documented and rehearsed incident response, including NDPC breach notification.
`REQ-SEC-038` (MUST) — **Static egress IP under change control.** Altering it breaks OPay connectivity.

---

## 15. Analytics

`REQ-ANL-001` (MUST) — Every event carries `eventId`, `eventName`, `timestampUtc`, pseudonymised `playerId`, `sessionId`, `channel`, `appVersion`, **`gameCode`**, `stateCode`.

`REQ-ANL-002` (MUST) — Server-side emission for anything money- or outcome-related. Client events are UX telemetry only.

`REQ-ANL-005` (MUST) — **Analytics are first-party. The back office is the destination.** Events are written to a Betplus-owned append-only store and surfaced through the back office Analytics section (`REQ-BO-012`). No third-party analytics platform receives player events.

This is a compliance decision as much as a technical one. A hosted analytics platform would move player data across borders and pull analytics into the cross-border transfer question at C-11 and `REQ-COMP-045`. Keeping the pipeline first-party removes that dependency entirely and makes `REQ-ANL-003` enforceable by construction rather than by vendor configuration.

`REQ-ANL-006` (MUST) — Raw events are retained 25 months per `REQ-DATA-001` and partitioned monthly. Dashboards read pre-aggregated rollups, never raw events, so no reporting query can degrade the play path.

`REQ-ANL-007` (MUST) — Metrics are segmentable by channel, game, app version, state, locale and accessibility-relevant settings, where privacy-safe.

`REQ-ANL-008` (MUST) — Commercial metrics are never reported in isolation. Activation, repeat play and retention are always paired with complaints, loss comprehension, limit usage, self-exclusion integrity, support contacts and responsible-play indicators.

### 15.1 Core events

| Event | Key properties |
|---|---|
| `registration_started` / `completed` | channel |
| `opay_wallet_validated` / `validation_failed` | had_wallet, latency_ms |
| `nin_verification_started` / `succeeded` / `failed` | attempt_count, failure_code |
| `kyc_tier_upgraded` | from_tier, to_tier |
| `geo_attribution_resolved` / `failed` | state_code, confidence, primary_signal |
| `state_not_licensed_blocked` | state_code, channel |
| `registry_check_performed` | registry_code, result, cache_hit |
| `deposit_initiated` / `otp_submitted` / `succeeded` / `failed` | amount_kobo, pay_method, failure_code |
| `ticket_created` | ticket_id, game_code, stake_kobo, channel, state_code |
| `ticket_settled` | ticket_id, game_code, tier, gross, tax, net |
| `blackred_prediction_made` | card_count, selection |
| `heritage_tile_revealed` | sequence, position, item_number, is_match |
| `payout_dispatched` / `succeeded` / `failed` | amount_kobo, latency_ms, failure_code |
| `float_alert_raised` | level, balance_kobo, cover_days |
| `second_chance_lodged` / `result_notified` | draw_id, partner_ref, won |
| `ussd_session_started` / `screen_viewed` / `ended` | state, end_reason |
| `turnover_progress` | deposit_id, staked_kobo, required_kobo |
| `rg_limit_set` / `cooloff_started` / `self_excluded` | type, duration |
| `withdrawal_requested` / `completed` | amount_kobo, source_balance |

### 15.2 Required funnels

1. **Acquisition** — landing → registration → OPay validated → **NIN verified** → first deposit → first play. The NIN step is the significant new drop-off point (M14); if it collapses conversion, that is a product problem to solve, not a metric to accept.
2. **USSD play** — session start → game selected → stake → confirmed → funded → result (M9).
3. **Funding** — initiated → OTP sent → OTP submitted → confirmed (M5).
4. **Payout** — settled win → tax applied → winnings credited → dispatched → OPay confirmed (M6, M7).
5. **Cross-game** — first game played → second game played (M2).
6. **Geo** — attribution attempted → resolved → licensed → play permitted (M12). Failures here are silent revenue loss and must be visible.

`REQ-ANL-003` (MUST) — Analytics never receives raw MSISDN, NIN, BVN, date of birth, or fine-grained coordinates.

`REQ-ANL-004` (MUST) — Marketing and attribution SDKs require consent and are disabled entirely for excluded players.

---

## 16. Compliance

> Requires Nigerian gaming counsel review and sign-off before build. The statements below are the engineering team's working understanding and are not legal advice.

### 16.1 Gaming licensing

There is no federal lottery regulator for the states. The Supreme Court nullified the National Lottery Act in November 2024, holding that authority to regulate lotteries and games of chance rests with state governments; the National Lottery Regulatory Commission's remit is effectively limited to the FCT.

Coordination has emerged: over twenty states have formed the Federation of State Gaming Regulators of Nigeria, with a Universal Reciprocity Certificate authorising online sports betting, online casino, public online lottery and promotional competitions across member states. The framework applies to online operations, and non-member states must be handled separately.

`REQ-COMP-001` (MUST) — Written confirmation of the licensing route, and the certificate or state licences, in place before public launch in any state. **Both games must be covered.** BlackRed as instant fixed-odds prediction and Heritage as instant-win with a draw component may fall under different categorisations.

`REQ-COMP-002` (MUST) — The licence footprint is configuration with expiry dates. The system refuses play in any state whose licence is not `ACTIVE`.

`REQ-COMP-003` (MUST) — Game rules, prize tables, odds disclosure and RNG certification filed with each licensing regulator as required.

`REQ-COMP-004` (MUST) — The per-category annual licence fee under the FSGRN framework is a material fixed cost reflected in the business case before build commitment. If the two games fall in different categories the exposure may apply twice.

`REQ-COMP-005` (SHOULD) — Track the federal position. A Central Gaming Bill was declined in December; a future consolidation would change the model, so the jurisdiction module must not assume permanence.

### 16.2 Location and remittance

`REQ-COMP-010` (MUST) — Geo-fencing or verified location capture, and monthly per-state remittance reconciliation, are regulatory requirements under the FSGRN reform package, implemented per §7.7.

### 16.3 Tax

`REQ-COMP-020` (MUST) — Withholding deducted at payout at the rate and basis of the applicable state and remitted to that state's revenue service. Currently 5% resident and 15% non-resident; Lagos directs 5% on net winnings at point of payout.

`REQ-COMP-021` (MUST) — GGR levy accrued continuously per state and reported monthly. Currently a flat 11% under the FSGRN reforms.

`REQ-COMP-022` (MUST) — Corporate income tax on gaming income, with deductions permitted for winnings paid and regulator levies, at up to 30%.

`REQ-COMP-023` (MUST) — Stakes configured VAT-exempt per the Nigeria Tax Act 2025, effective 1 January 2026.

### 16.4 Anti-money laundering

`REQ-COMP-030` (MUST) — Betplus is a Designated Non-Financial Business or Profession under the Money Laundering (Prevention and Prohibition) Act 2022 and must be **registered with SCUML and enrolled on NFIU goAML before commencing operations**, including beta.

`REQ-COMP-031` (MUST) — A Chief Compliance Officer of senior management cadre is appointed and owns the AML programme.

`REQ-COMP-032` (MUST) — The platform implements `BP-AML-001` in full: dual balance, 1× turnover, third-party prohibition, 95% RTP ceiling, cross-game aggregation, transaction monitoring, threshold and suspicious transaction reporting, seven-year retention.

`REQ-COMP-033` (MUST) — **The prize table publication gate is an AML control**, not merely a commercial one. Bypassing it is a compliance breach.

### 16.5 Data protection

`REQ-COMP-040` (MUST) — Registration with the NDPC as a Data Controller of Major Importance before any production personal data is processed. The 200-data-subject threshold will be exceeded in the first week.

`REQ-COMP-041` (MUST) — A Data Protection Officer is appointed and named.

`REQ-COMP-042` (MUST) — Data processing agreements with every processor: OPay, SMS provider, identity vendor, USSD aggregator, draw partner, analytics vendor, hosting provider.

`REQ-COMP-043` (MUST) — Granular separate consent for account processing, **location processing**, marketing, and analytics. Records timestamped and versioned against the policy text in force.

`REQ-COMP-044` (MUST) — Data subject rights tooling with defined SLA.

`REQ-COMP-045` (MUST) — Cross-border transfer position documented and approved. Where the hosting provider places data outside Nigeria, the lawful basis is established and recorded before launch.

`REQ-COMP-046` (MUST) — DPIA completed for the play, KYC, location and analytics flows. The location and NIN flows are the two most likely to attract scrutiny.

### 16.6 Player protection

`REQ-COMP-050` (MUST) — 18+ enforcement on every channel.
`REQ-COMP-051` (MUST) — State exclusion registry integration as a licence condition.
`REQ-COMP-052` (MUST) — Published game rules per game, including full odds disclosure and the statement that outcome is fixed at purchase.
`REQ-COMP-053` (MUST) — Terms and Privacy Policy accessible pre-registration on every channel.
`REQ-COMP-054` (MUST) — Published complaints and dispute procedure with defined SLAs, per state where required.
`REQ-COMP-055` (MUST) — Prize tables and RTP certified by a qualified game mathematician, model retained on file.
`REQ-COMP-056` (MUST) — Advertising complies with applicable standards. Given Heritage's cultural theme, advertising must additionally avoid implying royal or traditional endorsement.

---

## 17. Testing and Certification

### 17.1 Strategy

| Layer | Requirement |
|---|---|
| Unit | ≥ 85% line coverage on both engines, wallet, ledger, tax and payout; ≥ 70% elsewhere |
| Integration | Contract test and simulated-failure test for every external dependency |
| Contract | **Engine Contract conformance suite**, run against both engines, gating any engine release |
| End-to-end | Full journey automated on Web, iOS, Android and a USSD simulator, for both games |
| Load | Sustained and burst targets verified on the actual hosting topology before each major release |
| Chaos | Fault injection on OPay, registry, geo provider, draw partner, Redis and each engine independently |
| Security | SAST, SCA, DAST in CI across both toolchains; annual external penetration test |

### 17.2 Game and money tests

`REQ-QA-001` (MUST) — **Monte Carlo validation**: simulate ≥ 10,000,000 tickets per game against the active prize table; assert realised tier frequencies within statistical tolerance and realised RTP matching the model, both gross and net of withholding.

`REQ-QA-002` (MUST) — **RTP ceiling test**: assert every tier of every published table sits at or below 95%.

`REQ-QA-003` (MUST) — **Determinism**: same `(seed, ticketId, input)` reproduces byte-identical output across restarts and deployments, for both engines.

`REQ-QA-004` (MUST) — **Engine purity**: static analysis asserts no engine performs database access, external network calls, filesystem writes, or randomness generation.

`REQ-QA-005` (MUST) — **Prohibited randomness**: build fails on `rand`, `mt_rand`, `shuffle`, `array_rand`, `str_shuffle` in PHP, and on the `random` module in the Heritage engine.

`REQ-QA-006` (MUST) — **Outcome-leak test**: assert no API response prior to reveal completion contains unrevealed numbers, the winning set, the tier, or any correlated field including response size and timing within tolerance.

`REQ-QA-007` (MUST) — **Ledger invariants**: after every simulated scenario including failure injection, assert every journal balances, no balance is negative, and every tax deduction has a matching state-attributed payable entry.

`REQ-QA-008` (MUST) — **Idempotency**: every mutating endpoint called 100× concurrently with the same key produces exactly one effect.

`REQ-QA-009` (MUST) — **Double-payout**: aggressively replay settlement and OPay callbacks; assert exactly one payout per ticket.

`REQ-QA-010` (MUST) — **Cross-game isolation**: assert a fault injected into one engine does not affect the other game's availability, and that platform limits aggregate correctly across games.

`REQ-QA-011` (MUST) — **Cosmetic independence**: simulate a large population across all Heritage traditions and both leader types; assert no statistically significant difference in outcome distribution.

`REQ-QA-012` (MUST) — **Quick Pick parity**: assert Quick Pick and manual selection produce statistically identical distributions.

`REQ-QA-013` (MUST) — **BlackRed draw fairness**: assert each card position is 50/50 within statistical tolerance over a large sample.

`REQ-QA-014` (MUST) — **USSD screen length**: every rendered screen in every locale asserted ≤ 160 characters; build fails otherwise. Pidgin strings are frequently longer than the English source.

`REQ-QA-015` (MUST) — **OPay unit test**: assert payout requests are sent in kobo and payout callbacks are parsed as Naira. A single test preventing a 100× reconciliation error.

`REQ-QA-016` (MUST) — **Signature scheme test**: assert collections sign with HMAC-SHA512 and payouts with RSA-SHA256, and that mixing them fails loudly rather than silently.

`REQ-QA-017` (MUST) — **Float exhaustion test**: simulate insufficient float; assert winnings remain credited, payout queues, alert fires, and no prize is lost.

`REQ-QA-018` (MUST) — **Fail-closed tests**: registry unavailable with stale cache → refusal, no money moves; geo confidence below threshold → refusal; unlicensed state → correct error; licence expiry crossed mid-day → play stops at expiry, not at next deploy.

`REQ-QA-019` (MUST) — **Tax correctness matrix**: for `(state, residency, prize, basisDefinition, rulesetVersion)`, assert the deduction matches an independently computed expected value and that historical tickets recompute under their original ruleset.

`REQ-QA-020` (MUST) — **Turnover test**: assert 1× turnover across games unlocks correctly FIFO, and that an un-staked deposit is never permanently retained.

`REQ-QA-021` (MUST) — **Exclusion enforcement**: a registry-excluded NIN is blocked on Web, app and USSD, across both games, receives no marketing, and can still withdraw.

`REQ-QA-022` (MUST) — **Abandonment**: tickets abandoned at each stage auto-settle correctly and pay winners.

`REQ-QA-023` (MUST) — **Catalogue integrity**: assert the number→item mapping is one-to-one across all 90 numbers and stable across rounds and restarts.

`REQ-QA-024` (MUST) — **Location spoofing**: mock-location, VPN and proxy scenarios detected and blocked in automated tests.

`REQ-QA-025` (MUST) — **Diacritic rendering**: Yoruba and Igbo item names render correctly on Web, iOS, Android and SMS, with the transliteration fallback exercised.

`REQ-QA-026` (MUST) — **Deployment test**: an atomic deploy and rollback is exercised on staging before every production release.

### 17.3 Certification

`REQ-CERT-001` (MUST) — RNG certified by an accredited test lab, covering both engines' consumption of platform seeds.
`REQ-CERT-002` (MUST) — Game rules and prize tables certified and filed per licensing state, per game.
`REQ-CERT-003` (MUST) — OPay integration passes sandbox certification before production credentials are issued.
`REQ-CERT-004` (MUST) — Independent security assessment on file before launch.
`REQ-CERT-005` (MUST) — State exclusion registry integration validated and accepted by the regulator before go-live in that state.
`REQ-CERT-006` (MUST) — App store approvals obtained: Google Play real-money gambling approval and Apple licensed-entity submission, both before public release.

### 17.4 Release gates

No release proceeds to production unless:

- All in-scope MUST requirements are verified
- Monte Carlo validation passes for the exact prize table shipping, for every active game
- **RTP ceiling test passes for every tier of every game**
- Engine Contract conformance passes for every registered engine
- Tax correctness matrix passes for every licensed state
- Fail-closed tests pass
- Float exhaustion test passes
- Zero open Critical or High security findings
- Reconciliation dry-run passes on a full day of staging data including per-state tax accounts and float
- Cultural advisory sign-off recorded for every published Heritage catalogue item
- Rollback rehearsed
- Compliance sign-off recorded

---

## 18. Release Plan

### Phase 0 — Foundations (blocking; no feature build)

**Legal and commercial**
- Confirm the licensing route and launch state footprint, covering both game categories
- Business case reflecting per-category licence fees and the 11% GGR levy
- Register with SCUML; enrol on NFIU goAML; register with NDPC; appoint CCO and DPO
- Contract the 5/90 draw partner, identity vendor, USSD aggregator and SMS provider

**OPay**
- Merchant account; RSA key pairs for test and production; public key submitted and reviewed
- IP whitelisting configured both directions
- Confirm payout limits, SLA and MDR
- Disable OPay-side payout review
- Initial float transfer arranged; top-up runbook drafted

**Infrastructure**
- Provision VPS or dedicated server with WHM, root, static IP, Redis and Python support
- Staging environment mirroring production topology
- Backup and restore procedure proven

**Product**
- Actuarial certification of both prize tables as specified in §8.4 and §9.4
- Cultural advisory panel engaged; tradition list and depiction constraints agreed
- Google Play real-money gambling application submitted

**Exit criteria:** licensing route confirmed in writing; both prize tables certified; OPay sandbox working end to end; SCUML and NDPC registrations submitted; hosting provisioned with static IP.

### Phase 1 — Platform core

Wallet Service and ledger. Identity, KYC and OPay-assisted registration. Game Registry and Engine Contract. Fairness Service. Payment Orchestrator with collections. Geo and state attribution. Tax engine. Back office core. BlackRed engine behind the contract.

**Exit criteria:** end-to-end paid BlackRed play on Web in staging with correct state attribution and tax deduction; Monte Carlo and RTP ceiling passing; ledger and tax invariants holding under chaos.

### Phase 2 — Payout, float and Heritage

Payout engine with `OpayWalletNg`. Float monitoring, alerting and console. Heritage engine behind the contract. Heritage catalogue with cultural sign-off. Draw Partner Bridge. Notifications. Full responsible gambling suite including registry integration.

**Exit criteria:** both games playable end to end; payouts landing in OPay wallets; float alerts firing under simulated exhaustion; registry exclusions enforced including fail-closed behaviour.

### Phase 3 — Mobile app

React Native build for Android and iOS. Native modules for pinning, root/jailbreak detection, mock-location detection and secure storage. Store submissions.

**Exit criteria:** both games playable on both platforms; store approvals granted; performance targets met on a low-end Android device.

### Phase 4 — USSD

Aggregator integration across all four networks. Shared shortcode. Registration with OPay name lookup. In-session funding via Bank Account + OTP. Both games on USSD. RG menus.

**Exit criteria:** identical odds demonstrated across all channels for both games; ≤ 5 screens to a funded ticket for a returning player; screen-length tests passing in all locales.

### Phase 5 — Certification and closed beta

RNG certification. Penetration test and remediation. Regulator filings. Registry acceptance. Closed beta in **one state** — Lagos recommended, as the most developed regulatory environment — with real money and a low stake ceiling. Daily reconciliation including float.

**Exit criteria:** all release gates satisfied; zero reconciliation exceptions across 14 consecutive days; per-state remittance dry-run accepted by Finance.

### Phase 6 — Single-state launch

Full public launch in the beta state. Marketing. 24/7 on-call. Daily war-room for two weeks with float cover reviewed every morning.

Launching one state first is deliberate: it proves the geo, tax, registry and float machinery against one real regulator before multiplying the surface area.

### Phase 7 — Expansion

States added in licence-footprint order. **Each addition should be a configuration change plus a compliance filing.** If it is not, Phase 1 built the jurisdiction module wrong.

### Phase 8 — Post-launch

Yoruba, Igbo and Hausa localisation. Heritage collection mechanic. Per-tradition regalia variants. Referral programme. Additional draw partners. **A third game** — the real test of whether the Engine Contract works.

---

## 19. Risks

| ID | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R-01 | Licensing route unresolved, or the two games fall under different categories with duplicated fees | Medium | **Fatal** | Resolve in Phase 0 before spend |
| R-02 | **Float exhaustion during a jackpot cluster** | Medium | High | Tiered alerting, cover policy including largest-prize headroom, runbook, graceful degradation preserving the prize |
| R-03 | OPay single-rail outage halts all revenue | Medium | High | Provider abstraction from day one; second rail contractible without rebuild |
| R-04 | NIN verification collapses registration conversion | High | High | Instrument as M14; make the rationale visible to the player; consider staged capture |
| R-05 | OPay-only excludes non-OPay Nigerians | High | Medium | Accepted strategic constraint; measure the `NO_OPAY_WALLET` refusal rate and revisit |
| R-06 | Geo attribution unreliable on USSD | High | High | Multi-signal, fail closed, monitored; aggregator capability confirmed in Phase 0 |
| R-07 | **Hosting topology cannot meet throughput at growth** | Medium | High | Realistic launch targets; scale path defined and rehearsed before it is needed; load testing on the real topology |
| R-08 | cPanel constraints surface late — no Redis, no persistent workers, shared IP | Medium | High | VPS/dedicated with root confirmed in Phase 0 as a hard requirement |
| R-09 | Two-runtime complexity slows delivery | Medium | Medium | Engine Contract with conformance suite; platform owns everything dangerous |
| R-10 | App store rejection, particularly iOS real-money gaming rules | Medium | High | Licensed-entity submission, geo-gating, free download, no IAP wagering; Google Play approval sought in Phase 0 |
| R-11 | Withholding computed on the wrong basis, mis-paying every winner | Medium | High | Basis in the ruleset; tax correctness matrix |
| R-12 | Cultural offence from Heritage regalia depiction | Medium | **High and irreversible** | Named advisory sign-off per tradition; depiction constraints enforced in schema; publication gate |
| R-13 | Registry unavailability causes visible outage under fail-closed | Medium | Medium | Cache with defined staleness, redundancy, clear messaging, monitored |
| R-14 | Draw partner integration manual or unreliable | Medium | Medium | Adapter pattern, multi-partner support, never blocks play |
| R-15 | Signature scheme confusion between collections and payouts | High | Medium | Separate signers, dedicated test |
| R-16 | Kobo/Naira unit error in payout callbacks | Medium | High | Dedicated test |
| R-17 | Python engine throughput under the GIL | Medium | Medium | Multi-process deployment, load-tested |
| R-18 | Regulator objects to the predetermined-outcome model | Medium | High | True Draw mode behind a flag; disclose transparently and file upfront |
| R-19 | Insider prize manipulation | Low | **Critical** | Maker-checker, segregation of duties, real-time alerting, immutable audit, replay tooling |
| R-20 | Problem gambling harm, particularly via BlackRed's fast cycle | Medium | High | Full RG suite, per-game velocity limits, registry integration, no dark patterns |
| R-21 | NDPC or SCUML enforcement for unregistered processing | Medium | High | Register before beta |
| R-22 | Static IP change breaks OPay connectivity | Low | High | IP under change control; documented as a hard dependency |

---

## 20. Items Awaiting Confirmation

Product decisions are made in place. The following depend on third parties and are confirmed in Phase 0.

| ID | Item | Owner | Blocks |
|---|---|---|---|
| **C-01** | Licensing route and launch state footprint; whether both games fall under one category | Legal | Everything |
| **C-02** | Per-category annual licence fee applicable to Betplus | Legal / Finance | Business case |
| **C-03** | OPay payout limits, SLA and MDR | Payments | M6, manual review threshold, float model |
| **C-04** | Identity vendor for NIN and BVN, and per-check cost | Payments | §7.2.3, unit economics |
| **C-05** | USSD aggregator selection; what location signals it can supply | Eng / Partnerships | USSD attribution |
| **C-06** | Shortcode allocation across MTN, Airtel, Glo, 9mobile | Partnerships | USSD |
| **C-07** | 5/90 draw partner selection and interface capability | Partnerships | Heritage second chance |
| **C-08** | State exclusion registry — real-time API or batch, SLA, data terms | Compliance | RG architecture, fail-closed thresholds |
| **C-09** | Confirmation of WHT basis and rate per launch state | Finance / Legal | Tax engine |
| **C-10** | Google Play real-money gambling approval for Nigeria | Mobile / Legal | Android release |
| **C-11** | Hosting provider data residency and cross-border transfer basis | Legal / Eng | NDPA compliance |
| **C-12** | Minimum withdrawal, fee policy, and who bears the OPay MDR | Finance | Wallet, unit economics |

---

## 21. Appendices

### 21.1 Glossary

| Term | Definition |
|---|---|
| **Betplus** | The platform and brand |
| **BlackRed** | Instant binary colour-prediction game; PHP engine |
| **Heritage** | Culture-themed instant-win game with 5/90 second chance; Python engine |
| **Engine Contract** | The `resolve`/`replay`/`describe` interface every game implements |
| **Play Balance** | Deposit-funded balance; sole source of stakes |
| **Winnings Balance** | Winnings-funded balance; freely withdrawable |
| **1× turnover** | A deposit becomes withdrawable once staked once, on any game |
| **RTP** | Return to player — expected value returned as prizes per unit staked |
| **RTP ceiling** | The 95% platform maximum; an AML control |
| **Float** | Pre-funded merchant balance at OPay from which payouts deduct |
| **MDR** | Merchant discount rate; OPay's fee, deducted from float in addition to the payout |
| **State of play** | The Nigerian state attributed to a ticket, determining licence, tax and reporting |
| **Winning Set** | In Heritage, the 5 of 9 board positions designated as winners |
| **5/90** | The dominant Nigerian lotto format: five numbers from 1–90 |
| **FSGRN / URC** | Federation of State Gaming Regulators of Nigeria; Universal Reciprocity Certificate |
| **SCUML / NFIU / EFCC** | Nigeria's AML supervisor, financial intelligence unit, and enforcement agency |
| **NDPA / NDPC** | Nigeria Data Protection Act 2023 and its Commission |
| **NIN / BVN** | National Identification Number; Bank Verification Number |
| **Kobo** | Minor unit of the Naira; all money stored as integer kobo |
| **Maker-checker** | Dual approval where the initiator cannot approve their own action |
| **Fail closed** | A dependency whose unavailability blocks play rather than being bypassed |

### 21.2 Configuration defaults

| Parameter | Default |
|---|---|
| `stake_min_kobo` | 10000 (₦100) |
| `stake_max_kobo` | 2000000 (₦20,000) |
| `turnover_multiplier` | 1.0 |
| `rtp_ceiling` | 0.95 |
| `rtp_min` / `rtp_max` | 0.40 / 0.95 |
| `abandon_ttl` | 24 h |
| `max_concurrent_tickets_per_game` | 1 |
| `geo_min_confidence` | 0.80 |
| `registry_cache_ttl` | 15 min |
| `registry_max_staleness` | 6 h |
| `callback_timeout_s` | 90 |
| `ussd_payment_wait_s` | 25 |
| `ussd_session_ttl_min` | 7 |
| `ussd_resume_window_min` | 10 |
| `manual_review_threshold_kobo` | 100000000 (₦1,000,000) |
| `float_warning_multiple` | 3× daily payout |
| `float_critical_multiple` | 1× daily payout |
| `float_halt_floor` | Largest single possible prize |
| `sc_stake_ratio` | 0.10 |
| `sc_cutoff_minutes` | 20 |
| `sc_max_roll` | 3 |
| `reality_check_plays` | 20 |
| `reality_check_minutes` | 30 |
| `quiet_hours` | 21:00–07:00 WAT |
| `wht_rate_resident` | 0.05 |
| `wht_rate_non_resident` | 0.15 |
| `wht_basis_definition` | `NET_WINNINGS` |
| `ggr_levy_rate` | 0.11 |
| `vat_on_stakes` | exempt |
| `retention_years` | 7 |

### 21.3 Prize tables at a glance

**BlackRed** — fair 50/50 draw per card, margin taken in the multiplier.

| Cards | Probability | Multiplier | RTP |
|---|---|---|---|
| 1 | 1 in 2 | 1.85× | 92.5% |
| 2 | 1 in 4 | 3.60× | 90.0% |
| 3 | 1 in 8 | 7.00× | 87.5% |
| 4 | 1 in 16 | 13.50× | 84.4% |
| 5 | 1 in 32 | 26.00× | 81.3% |

**Heritage** — predetermined outcome, 9-tile board, 5 picks.

| Tier | Match | Probability | Outcome |
|---|---|---|---|
| Jackpot | 5 of 5 | 2.00% | 25× stake |
| High | 4 of 5 | 6.00% | 5× stake |
| Second chance | 3 of 5 | 16.00% | 5/90 draw entry at 10% of stake |
| Loss | 1–2 of 5 | 76.00% | — |

Modelled RTP 81.6%.

### 21.4 Requirement index

| Prefix | Module | Section |
|---|---|---|
| `REQ-ARCH` | Architecture | 5.1–5.4 |
| `REQ-HOST` | Hosting topology | 5.5 |
| `REQ-GEC` | Game Engine Contract | 6 |
| `REQ-RNG` | Randomness and fairness | 7.1 |
| `REQ-ID` | Identity and KYC | 7.2 |
| `REQ-PAY` | OPay collections | 7.3 |
| `REQ-WAL` | Wallet and ledger | 7.4 |
| `REQ-PO` | Payout engine | 7.5.1 |
| `REQ-FLOAT` | Float management | 7.5.2 |
| `REQ-TAX` | Tax engine | 7.6 |
| `REQ-GEO` | Geolocation and state attribution | 7.7 |
| `REQ-NOT` | Notifications | 7.8 |
| `REQ-RG` | Responsible gambling | 7.9 |
| `REQ-BO` | Back office | 7.10 |
| `REQ-TKT` | Ticket lifecycle | 7.11 |
| `REQ-BR` | BlackRed | 8 |
| `REQ-HG` | Heritage | 9 |
| `REQ-WEB` / `REQ-APP` / `REQ-USSD` | Channels | 10 |
| `REQ-DATA` | Data model and retention | 11 |
| `REQ-NFR` | Non-functional | 13 |
| `REQ-SEC` | Security | 14 |
| `REQ-ANL` | Analytics | 15 |
| `REQ-COMP` | Compliance | 16 |
| `REQ-QA` / `REQ-CERT` | Testing and certification | 17 |

---

*End of document.*
