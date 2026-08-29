# API Contracts — `platform-api`

**Base:** `{APP_PUBLIC_URL}{APP_BASE_PATH}` · **Transport:** JSON over HTTPS
**Auth:** session cookie via `AuthMiddleware` (`MODE_REQUIRED`)
**Route table:** declared inline in `src/Bootstrap/App.php::registerRoutes()`

---

## 1. Endpoints (16 routes)

### Public

| Method | Path | Controller | Rate limit |
|---|---|---|---|
| GET | `/api/health` | `HealthController::check` | none |
| POST | `/api/auth/signup/lookup` | `SignupController::lookup` | `signup_lookup` (5/min) |
| POST | `/api/auth/signup/verify` | `SignupController::verify` | `signup` (3/min) |
| POST | `/api/auth/signup/complete` | `SignupController::complete` | `signup` (3/min) |
| POST | `/api/auth/login` | `AuthController::login` | `login` (5/min) |
| POST | `/api/auth/forgot/lookup` | `AuthController::forgotLookup` | `forgot_lookup` (5/min) |
| POST | `/api/auth/forgot/reset` | `AuthController::forgotReset` | `forgot_reset` (5/min) |
| POST | `/api/callbacks/momo` | `CallbackController::momo` | **none** |

### Authenticated

| Method | Path | Controller | Rate limit |
|---|---|---|---|
| POST | `/api/auth/logout` | `AuthController::logout` | — |
| POST | `/api/auth/change-password` | `AuthController::changePassword` | `password_change` (3/min) |
| GET | `/api/me` | `MeController::show` | `general` (60/min) |
| GET | `/api/transactions` | `TransactionsController::index` | `general` |
| POST | `/api/deposits` | `DepositController::create` | `general` |
| GET | `/api/deposits/:id` | `DepositController::get` | — |
| POST | `/api/deposits/:id/verify` | `DepositController::verify` | — |
| POST | `/api/withdrawals` | `WithdrawalController::create` | `general` |
| GET | `/api/withdrawals/:id` | `WithdrawalController::get` | — |
| POST | `/api/withdrawals/:id/verify` | `WithdrawalController::verify` | — |
| POST | `/api/game/play` | `GameController::play` | `play` (30/min) |
| GET | `/api/stakes` | `StakesController::index` | `general` |

## 2. The play endpoint

`POST /api/game/play` is the whole game in one call — client locks a 12-card deck, picks
colours, sets a stake; the server debits, decides, credits and returns the result.

**Request**

```json
{
  "gameType": 3,
  "colorPicks": ["red", "black", "red"],
  "stakePesewas": 1000,
  "lockedDeck": ["AH","5D","9H","KD","3H","7D","2C","8S","JC","QS","4C","6S"]
}
```

Validation, in order, before any write: game type ∈ 1–5 · `count(colorPicks) === gameType` ·
each pick ∈ {red, black} · stake ≥ 200 pesewas · deck length exactly 12 · all card codes valid ·
no duplicates · exactly 6 red and 6 black.

**Response**

```json
{
  "refNumber": "STK-a1b2c3d4e5f6",
  "outcome": "loss",
  "drawnCards": ["AH","8S","9H"],
  "payoutPesewas": 0,
  "stakePesewas": 1000,
  "multiplier": 20,
  "gameType": 3,
  "colorPicks": ["red","black","red"],
  "playBalance": 4200,
  "payoutBalance": 0
}
```

**Error codes** (`HttpException` with a machine code): `engine_disabled` (503),
`bad_game_type`, `picks_length_mismatch`, `bad_pick_value`, `stake_too_low`, `stake_too_high`,
`deck_wrong_size`, `bad_card_code`, `deck_has_duplicates`, `deck_unbalanced`,
`daily_limit_exceeded`, `insufficient_balance` (400), `account_missing`, `wallet_missing` (500).

## 3. Distance from the PRD's API design (§11)

| PRD | Current |
|---|---|
| `/v1` version prefix | Unversioned `/api` |
| `Idempotency-Key` on mutating requests | **Absent on every endpoint** |
| RFC 7807 problem details with stable `code` | Custom `{code, message}` shape — the stable-code discipline is already there |
| `X-Request-Id` on every response | `RequestLogger` logs but does not emit a correlation header |
| Game-agnostic `POST /v1/tickets` with `game_code` | `POST /api/game/play`, single game hard-wired into the route |
| Ticket lifecycle states (`CREATED`→…→`SETTLED`) | No ticket entity — one synchronous call, no intermediate state |
| Reveal endpoints (`/reveal`, `/quickpick`) | Not applicable — outcome and reveal are the same call |
| `location` on ticket creation, jurisdiction on response | Absent |
| Tax block on settlement | Absent |
| Amounts as `*_kobo` with explicit `NGN` | `*Pesewas`, implicit GHS |

## 4. Webhook

`POST /api/callbacks/momo` — public, unauthenticated, unrated. One endpoint dispatches to
deposit or withdrawal by looking up the `exttrid` carried in the body.

`.env` defines `ANM_CALLBACK_SHARED_SECRET`, so signature verification is intended.
**Confirm it is actually enforced in `CallbackController::momo` before this pattern is carried
forward** — the PRD makes it mandatory (`REQ-PAY-014`) and an unverified money-moving webhook
is the highest-severity class of defect in a payments system.

## 5. Contracts to preserve

- **The locked-deck contract.** The client commits to a deck before the server draws. It is
  cheap provable-fairness and generalises to the PRD's seed-commitment scheme (`REQ-RNG-007`).
- **Stable machine-readable error codes** on every failure.
- **Per-endpoint rate-limit buckets** sized by what the endpoint costs, not one global limit.
- **Cost-aware limiting** — the provider lookup is limited harder because each call is billable.
