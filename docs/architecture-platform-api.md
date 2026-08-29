# Architecture — Platform API (`platform-api`)

**Root:** `BlackRed/EngineAndServices/` (`src/` + `public/index.php`)
**Type:** backend · **Runtime:** PHP 8.1+ under Apache · **Pattern:** layered monolith

---

## 1. Executive summary

A hand-rolled PHP micro-framework serving a JSON API under `/api/*`. Request flows through a
front controller into a middleware pipeline, to a thin controller, into a service that owns
business logic and talks to MariaDB through a PDO wrapper. There is no framework dependency,
no ORM, no cache and no queue.

The code is disciplined for its size — `declare(strict_types=1)` everywhere, constructor
injection, prepared statements throughout, no string-built SQL, `password_hash`, per-endpoint
rate limits, security headers. The problems are structural rather than hygienic.

## 2. Request lifecycle

```
public/index.php
   └── Bootstrap\App::run()
         1. Config::load()            .env → typed accessors
         2. registerServices()        ~30 closure factories into Container
         3. Request::fromGlobals()
         4. registerRoutes()          16 routes, inline table
         5. dispatch()
              global middleware:  ErrorHandler → RequestLogger → SecurityHeaders
              route middleware:   auth.required? ratelimit.*?
              final handler:      Controller::method(Request, params)
         6. Response::send()
```

`App.php` is 493 lines and is simultaneously the DI container configuration, the route table
and the dispatcher. It is the single file that must be read to understand the system, and the
single file that every feature touches.

## 3. Layers

| Layer | Location | Responsibility | Assessment |
|---|---|---|---|
| Transport | `Http/Controllers/` | Parse, validate shape, serialise | Correctly thin |
| Middleware | `Http/Middleware/` | Auth, rate limit, errors, headers, logging | Clean pipeline, composable |
| Business | `Wallet/`, `Auth/` | Money, game, identity | **Overloaded — see §5** |
| Integration | `Integrations/` | ANM mobile money, Hubtel SMS | Adapter shape is right |
| Persistence | `Database/Connection.php` | PDO, `transactional()` | Thin, no repository layer |
| Config | `Bootstrap/Config.php`, `Config/SystemConfigService.php` | `.env` + DB-backed runtime config | Two config systems |

There is **no repository layer and no domain model**. Services build SQL inline and return
associative arrays. `Support/Money.php` is the only value object.

## 4. Runtime configuration

Two mechanisms coexist:

- **`.env`** via `Bootstrap\Config` — infrastructure and static limits (56 keys).
- **`systemConfig` table** via `SystemConfigService` — operationally tunable values read on
  every request: `engine.gameEngineEnabled`, `engine.houseWinThresholdPct`,
  `engine.dailyRevenueFloorPesewas`, `game.maxStakePesewas`, `game.dailyStakeLimitPesewas`.

The DB-backed layer is the right instinct and maps onto the PRD's requirement that prize
tables and limits be configuration. It has **no versioning, no effective dating, no
maker-checker and no audit trail** — all of which `REQ-GEC-020` and `REQ-BO-003` require.

## 5. The game engine — `Wallet/GameEngineService.php`

578 lines. `play()` is a single ~300-line method wrapping one database transaction.

### 5.1 What it does

```
validate input (game type 1–5, colour picks, stake, 12-card locked deck: 6 red + 6 black)
BEGIN TRANSACTION
  check daily stake cap for the player
  resolve PLAYER_PLAY / PLAYER_PAYOUT / HOUSE_REVENUE accounts
  SELECT wallet ... FOR UPDATE              ← per-player lock
  SELECT dailyRevenueSummary ... FOR UPDATE ← GLOBAL lock, all players serialise here
  decide engine path (see 5.2)
  pick drawn cards from the locked deck
  compare picks to draws → win / loss
  INSERT walletTransaction + 2 ledgerEntry   (stake: debit PLAY, credit HOUSE)
  UPDATE wallet cached balance
  if win: INSERT walletTransaction + 2 ledgerEntry (debit HOUSE, credit PAYOUT), UPDATE wallet
  UPDATE dailyRevenueSummary
  INSERT gameRound audit row
COMMIT
```

### 5.2 The decision rule — finding B-2

```php
netRevenueToday  = lossesToday - winsToday          // may be negative
effectiveRevenue = max(netRevenueToday, dailyFloor)

if ((winsToday + potentialPayout) * 100 <= effectiveRevenue * thresholdPct)
    enginePath = 'fair_random';   // cryptographic shuffle, player may win
else
    enginePath = 'forced_loss';   // a losing sequence is constructed

if (netRevenueToday < 0)
    enginePath = 'forced_loss';   // "house bleeding" guard, overrides the above
```

On `forced_loss` the engine picks a random position `j`, places a card of the **opposite**
colour to the player's pick at `j`, and fills the remaining positions randomly — the code
comments this as producing "a believable loss — they may have got most picks right, just the
one wrong."

**Consequences for the target build.** The probability of winning is not a property of the
game; it is a function of the house's net revenue at the moment of play. A player's odds
therefore depend on how other players have done that day. This is incompatible with:

- `REQ-GEC-001` — the engine is not deterministic given a seed; it depends on mutable shared state.
- `REQ-GEC-020`/`022` — there is no prize table with tier probabilities summing to 1.
- `REQ-GEC-023` — modelled RTP cannot be computed for certification.
- `REQ-BR-013` — published odds cannot state a true per-tier probability.
- `REQ-RNG-006` — chi-square monitoring against configured probabilities has no target to test.
- `REQ-BO-005` / `REQ-GEC-001` replay — a historical round cannot be reproduced without also
  reconstructing that day's revenue state.

This is a product and compliance decision before it is an engineering one, and it should be
resolved explicitly rather than carried forward by refactoring.

### 5.3 Other engine observations

- **Multipliers** are `private const MULTIPLIERS = [1=>2, 2=>10, 3=>20, 4=>50, 5=>100]` —
  hard-coded, and duplicated in `ussd/lib/game.php` as `GAME_MULTIPLIERS`.
- **Minimum stake** is `private const MIN_STAKE_PESEWAS = 200`, hard-coded, while the maximum
  is DB-configurable. `.env` also carries `GAME_MIN_STAKE_PESEWAS`, unused by the engine.
- **Business timezone** is `private const BUSINESS_TZ = 'Africa/Accra'`.
- **Randomness** uses `random_int` and `random_bytes` — cryptographically sound, but generated
  *inside the engine*, which `REQ-GEC-003` forbids.
- **The locked-deck contract** — client sends 12 cards, server validates 6 red / 6 black, no
  duplicates — is a genuinely good design and is worth carrying forward as a pattern.
- **Concurrency:** the `FOR UPDATE` on `dailyRevenueSummary` serialises *every* play across the
  whole platform onto one row. Throughput is bounded by that row's lock; the PRD targets
  1,000 tickets/s sustained (`REQ-NFR-011`).

## 6. Money and ledger

Double-entry is implemented correctly in shape:

```
STAKE      debit  PLAYER_PLAY:{id}     credit HOUSE_REVENUE
WIN_PAYOUT debit  HOUSE_REVENUE        credit PLAYER_PAYOUT:{id}
```

Every `walletTransaction` carries balanced `ledgerEntry` rows, wallets hold a
`cachedBalancePesewas` with a `version` column for optimistic concurrency, and writes happen
under `FOR UPDATE`. This is the strongest asset in the codebase and maps closely onto PRD §7.4.

Gaps against the target: no append-only enforcement on `ledgerEntry` (no triggers, no
permission split), no idempotency key on the engine path, `PLAYER_PAYOUT` naming instead of
`PLAYER_WINNINGS`, and the engine writes the ledger directly rather than calling a wallet
service (`REQ-ARCH-001`, `REQ-ARCH-002`).

## 7. Auth and identity

`Auth/` holds eight focused services — `SignupService`, `OtpService`, `SessionManager`,
`PasswordHasher`, `PasswordChangeService`, `ForgotPasswordService`, `RateLimiter`,
`AuthtokenVerifier`.

- Registration is a three-step wizard: `lookup` (mobile-money name lookup) → `verify` (OTP) →
  `complete`. The lookup step is rate-limited harder than the others because it costs money per
  call — a good instinct that carries directly to OPay wallet validation.
- Passwords use `password_hash` with configurable Argon2 cost.
- Sessions are database rows, not Redis. Horizontal scaling would need this changed.
- Forgot-password resets via a 4-character USSD code, rate-limited at 5/min with a comment
  noting the ~1.6M keyspace.

Identity is keyed on MSISDN and the mobile-money name lookup populates the registered name —
the exact pattern PRD §7.2.2 preserves for OPay.

## 8. Security posture

**Present:** prepared statements everywhere, `SecurityHeaders` middleware, per-endpoint rate
limiting with distinct buckets, `password_hash`, strict types, no `dd`/`var_dump` in `src/`,
`.htaccess` deny rules on `src/`, `logs/` and `migrations/`.

**Absent against the PRD:** secrets in a managed store (`REQ-SEC-005` — see B-5), MFA,
static analysis or SAST in CI (`REQ-SEC-006`), engine endpoint isolation (`REQ-SEC-011` —
there is no separate engine process to isolate), tamper-evident audit logging
(`REQ-SEC-034` — `auditLog` exists in the schema but nothing writes to it).

## 9. Testing

`composer test` runs `php tests/run.php`. **That file does not exist.** The only working test
runner in the repository is `ussd/tests/run.php`. There is no unit test suite, no coverage
measurement and no CI gate. PRD §17 requires ≥85% coverage on engine, wallet, ledger, tax and
payout, plus Monte Carlo, determinism, idempotency and ledger-invariant suites.

## 10. What to keep

| Asset | Verdict |
|---|---|
| `Http/` middleware pipeline, router, request/response | Keep — small, correct, framework-free |
| Double-entry ledger tables and posting pattern | Keep — closest thing to PRD §7.4 already built |
| Signup wizard with name lookup + OTP | Keep the flow, swap ANM → OPay |
| Rate limiter with per-endpoint buckets | Keep, move backing store to Redis |
| `SystemConfigService` DB-backed config | Keep the idea, add versioning + maker-checker |
| Locked-deck client contract | Keep as a pattern |
| `GameEngineService` decision rule | **Replace** — see B-2 |
| `Wallet/` as a single namespace for money + game | **Split** — wallet service vs engine |
| ANM / Hubtel clients | Replace, preserve the adapter shape |
| `Support/GhanaPhone.php`, pesewas arithmetic | Replace |
