# Port Manifest — BlackRed/EngineAndServices → Betplus

**Decision:** the existing BlackRed implementation is the starting point, not a reference.
14,579 lines of working PHP are carried forward where they earn their place, refactored where
the market or architecture changed, and deleted where they are the defect the rewrite exists to
correct.

Every file below has one of four dispositions:

| Action | Meaning |
|---|---|
| **PORT** | Move with mechanical changes only — namespace, currency rename, style |
| **REFACTOR** | Logic is right, implementation changes — provider swap, framework adaptation |
| **REPLACE** | Framework provides this; the file is discarded and its behaviour re-expressed |
| **DELETE** | Must not survive — this is what the rewrite corrects |

---

## The three-way split

| | Lines | Share |
|---|---|---|
| Carried forward (PORT + REFACTOR) | ~7,200 | 49% |
| Replaced by framework (REPLACE) | ~3,200 | 22% |
| Deleted as defect or dead market code (DELETE) | ~4,180 | 29% |

Roughly half the codebase moves. The replaced fifth is the *cheapest* part to give up —
routing and middleware are commodity. The expensive parts — authentication flows, money
orchestration, USSD screens, game mechanics — all carry forward.

---

## 1. The game engine — the one file that must not be copied wholesale

**`src/Wallet/GameEngineService.php` (578 lines) — SPLIT**

This file is simultaneously the most valuable and the most dangerous thing in the repository.

**KEEP (~90 lines) → `apps/engine-blackred/src/`** — the game mechanics are exactly right and
are what makes this "the exact game we want":

| What | Where today | Why it survives |
|---|---|---|
| Card code format `<rank><suit>` | `isValidCardCode()` :431 | Clean, compact, testable representation |
| Colour derivation from suit | `cardColor()` :441 | H,D = red · C,S = black |
| Locked-deck contract | `validateInput()` :406–427 | Client commits 12 cards, 6/6 balanced, no dupes — cheap provable fairness, generalises to the PRD's seed commitment |
| Position-by-position comparison | :189–197 | All must match, no partial prizes |
| Cryptographic Fisher-Yates | `secureShuffle()` :572 | Correct algorithm — but reseeded from the platform, see below |

**DELETE (~490 lines)** — everything else in the file:

- The `forced_loss` / `fair_random` decision rule (:159–185) and `pickDrawnCards()`'s
  loss construction (:468–524). **This is the defect the rewrite exists to correct.** Outcomes
  derived from house daily revenue cannot be certified, cannot publish true odds, and cannot be
  replayed. Replaced by the certified prize table at PRD §8.4 — 1.85× / 3.60× / 7.00× / 13.50× /
  26.00×.
- All ledger writes (:205–280) — the engine stops touching money (`REQ-ARCH-002`).
- All database access, `dailyRevenueSummary` handling, config reads — the engine becomes a pure
  function (`REQ-GEC-002`).
- `random_int` / `random_bytes` calls — the seed arrives from the platform Fairness Service
  (`REQ-GEC-003`).
- `MIN_STAKE_PESEWAS`, `BUSINESS_TZ = 'Africa/Accra'` — market constants.

## 2. Platform — `src/`

### Carried forward

| File | Lines | Action | Notes |
|---|---|---|---|
| `Auth/SignupService.php` | 359 | REFACTOR | Three-step wizard is the PRD's flow exactly. Mobile-money lookup → OPay wallet validate |
| `Auth/ForgotPasswordService.php` | 223 | PORT | |
| `Auth/SessionManager.php` | 200 | REFACTOR | DB sessions → Redis; add refresh rotation with reuse detection (`REQ-ID-026`) |
| `Auth/OtpService.php` | 169 | PORT | |
| `Auth/PasswordChangeService.php` | 128 | PORT | |
| `Auth/AuthtokenVerifier.php` | 110 | PORT | |
| `Auth/RateLimiter.php` | 97 | REFACTOR | Backing store DB → Redis; per-endpoint buckets kept |
| `Auth/PasswordHasher.php` | 78 | PORT | Argon2 with configurable cost |
| `Wallet/WithdrawalService.php` | 644 | REFACTOR | Orchestration shape is right; ANM → OPay; add 1× turnover, drop the 50% rule |
| `Wallet/DepositService.php` | 594 | REFACTOR | Same — ANM → OPay Bank Account + OTP |
| `Wallet/TransactionsService.php` | 243 | PORT | Unified history feed |
| `Wallet/StakesService.php` | 221 | REFACTOR | Reads `gameRound` → reads `ticket` + `ticketOutcome` |
| `Integrations/AnmClient.php` | 522 | REFACTOR | **Structure kept, provider replaced.** Client + call log + error taxonomy is the right shape for `OpayCollectionsClient` |
| `Integrations/AnmSignatureBuilder.php` | 41 | REFACTOR | Becomes two signers — HMAC-SHA512 collections, RSA-SHA256 payouts (`REQ-PAY-010`) |
| `Integrations/HubtelSmsClient.php` | 176 | REFACTOR | Nigerian SMS provider |
| `Sms/SmsService.php` | 135 | PORT | Template + log + send |
| `Config/SystemConfigService.php` | 79 | REFACTOR | Add versioning, effective dating and maker-checker (`REQ-GEC-020`) |
| `Support/Money.php` | 74 | REFACTOR | Pesewas → kobo |
| `migrations/001_initial_schema.sql` | 1,142 | REFACTOR | Source for the Laravel migrations. 13 unused tables dropped, kobo rename, new tables added |
| `docs/reconstructed-ddl.sql` | — | REFACTOR | Reconstructed `gameRound`, `dailyRevenueSummary`, `eventLog` — fills the 003–007 migration gap. **Validate against production before use** |

> **The schema gap is larger than first reported.** `ussd/migrations/008` states it runs "after
> the web app's migrations 001-007", and **003 through 007 do not exist in this repository**. The
> missing tables were created in that lost block. `ussd/tests/run.php` holds a SQLite test-double
> schema for twelve tables that its author verified against a live `SHOW COLUMNS` — the best
> available cross-check.

**Three schema lessons to carry into the new tables, not just port:**

1. **`ticket` needs a composite index on `(playerId, createdAt)`.** The daily stake-cap check
   runs on *every* play in both current engines against `gameRound` with exactly that predicate.
   If production indexes `playerId` alone, it degrades badly at millions of rows — and the new
   `ticket` table inherits the same access pattern.
2. **Record the channel on the ticket directly.** `gameRound` has no channel column, so web
   versus USSD attribution requires an indirect join through `walletTransaction`. `ticket`
   already carries `channel` in the target model; this confirms why that matters.
3. **`eventLog.userAgent` is truncated to 512 in code while every comparable column in the schema
   is `VARCHAR(500)`** — silent truncation or an insert failure under strict mode. Pick one width
   and enforce it in both places.

### Replaced by Laravel

| File(s) | Lines | Why |
|---|---|---|
| `Bootstrap/App.php`, `Container.php`, `Config.php` | 716 | Kernel, service providers, config |
| `Http/Router.php`, `Pipeline.php`, `Request.php`, `Response.php`, `Middleware.php` | 460 | Laravel routing and middleware |
| `Http/Middleware/*` (6 files) | 359 | Laravel middleware, plus its own rate limiter |
| `Http/Controllers/*` (10 files) | 1,000 | Rewritten as Laravel controllers — the logic inside is thin transport, so mostly mechanical |
| `Logging/Logger.php` | 94 | Laravel ships Monolog |
| `Database/Connection.php` | 215 | `DB::transaction()`; the `transactional()` helper's discipline is preserved in the Wallet repositories |
| `Validation/Validator.php` | 206 | Laravel validation |

**This is the cheapest 3,200 lines in the repository to give up.** Routing, middleware and
container wiring are commodity; the 33 back-office and analytics requirements that Filament and
Horizon deliver are not.

### Deleted

| File | Lines | Why |
|---|---|---|
| `Support/GhanaPhone.php` | 129 | Nigerian MSISDN handling, E.164 |

## 3. USSD — `ussd/`

**The channel becomes an adapter. It keeps its screens and loses its money logic entirely.**

### Carried forward — the valuable half

| File(s) | Lines | Action | Notes |
|---|---|---|---|
| `states/*.php` (23 files) | ~1,900 | REFACTOR | **The most valuable asset in the USSD codebase.** Screen copy, prompts, input handling and transitions all carry; each state calls the platform API instead of a local library |
| `lib/states.php` | 97 | PORT | State registry as source of truth |
| `lib/session.php` | 150 | REFACTOR | Redis primary, DB mirror for audit |
| `lib/response.php` | 128 | REFACTOR | Gateway payload shaping — Nalo → Nigerian aggregator |
| `lib/msisdn.php` | 65 | REFACTOR | Ghana → Nigeria normalisation |
| `workers/cleanup.php` | 36 | REFACTOR | Session reaper, cron |

### Deleted — the duplicate money path

| File | Lines | Why |
|---|---|---|
| `lib/withdrawal.php` | 869 | Second withdrawal implementation writing the ledger |
| `lib/deposit.php` | 583 | Second deposit implementation |
| `lib/game.php` | 545 | **Second game engine.** Same forced-loss rule, its own ledger writes, its own forked config — findings P-3 and P-4 |
| `lib/anm.php` | 521 | Second provider client |
| `lib/player.php` | 408 | Second identity implementation |
| `callbacks/momo.php` | 255 | Second callback endpoint — finding P-1 |
| `lib/hubtel.php` | 159 | Second SMS client |
| `lib/db.php` | 86 | Second database layer |
| `lib/password.php` | 72 | Second password implementation |
| `config.php` | 98 | The forked config — finding P-4 |

**4,096 lines deleted, and every one of them is a defect the architecture removes by
construction.** Once USSD reaches money only through `/v1`, channel divergence stops being
possible rather than being monitored.

## 4. Web — `public/app/`

10 static HTML pages, ~no shared code with the target. **Treated as a functional specification
and reference implementation**, then deleted. What to extract before deleting: the screen
inventory, the signup wizard sequencing, and the play-screen interaction model.

## 5. Market and naming changes applied throughout the port

| From | To | Scope |
|---|---|---|
| Pesewas | Kobo | ~40 column names, every SQL literal, `Money`, both engines |
| `GHS` | `NGN` | SQL string literals |
| `Africa/Accra` | `Africa/Lagos` | Business date computation |
| `PLAYER_PAYOUT` | `PLAYER_WINNINGS` | Account codes, wallet type enum |
| ANM | OPay | Collections and payouts |
| Hubtel | Nigerian SMS provider | Notifications |
| Nalo | Nigerian USSD aggregator | Gateway payloads |
| Ghana Card / Voter ID | NIN / BVN | KYC |
| 50% rule | 1× turnover | Withdrawal control |
| `gameRound` | `ticket` + `ticketOutcome` | Multi-game data model |

## 6. Port order

The port follows the epic sequence, because each epic's stories already describe the target
state of the code being ported into.

1. **Story 1.1** — scaffold, then port `Support/`, `Config/` and the schema foundation
2. **Stories 1.7–1.11** — port `Auth/*` (the largest clean win, ~1,065 lines)
3. **Stories 2.1–2.7** — port `Wallet/Deposit|Withdrawal|Transactions` with OPay swapped in
4. **Stories 3.1–3.3** — extract the ~90 lines of game mechanics into the pure engine, and
   write the certified prize table fresh
5. **Epic 8** — port the 23 USSD states as API-calling adapters

**Nothing is ported without its target story.** A file carried across ahead of the story that
defines its target state arrives with its old assumptions intact, which is how a refactor
quietly becomes a copy.
