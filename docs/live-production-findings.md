# Live Production Findings

> **These concern the system running today, not the Betplus refactor.** Each is independently
> actionable and none waits on the rewrite. Verified 10–11 August 2026 against
> `BlackRed/EngineAndServices` by direct code inspection.

---

## P-1 · CRITICAL · Payment callback endpoint is unauthenticated

**`POST /api/callbacks/momo` → `src/Http/Controllers/CallbackController::momo()`**

The route is registered in `src/Bootstrap/App.php` with an explicitly **empty middleware
array** — no auth, no rate limit. The handler performs **no signature verification**.

`ANM_CALLBACK_SHARED_SECRET` is declared in `.env` **with an empty value**, and is referenced
nowhere in `src/`. So the verification was never built and there is no secret to build it with —
the key is a placeholder someone added and never returned to.

**Exposure.** An unauthenticated, publicly reachable endpoint that moves money. Whether a
forged callback can credit a player's balance depends on what the handler validates against the
provider — that needs runtime confirmation — but a payment callback with no signature check is
critical by default.

**Fix.** Verify the HMAC using the shared secret already in `.env`, and add a source-IP
allowlist. This is `REQ-PAY-014` in the target build; it should not wait for it.

---

## P-2 · CRITICAL · USSD accepts an unverified MSISDN as identity

**`ussd/index.php`**

No signature, HMAC, shared-secret or source-IP check exists anywhere in the file. The player's
identity is read straight from the request body:

```php
$rawMsisdn = isset($incoming['MSISDN']) ? trim((string) $incoming['MSISDN']) : '';   // line 91
$msisdn = normalizeMsisdn($rawMsisdn);                                               // line 102
```

The HMAC machinery in `ussd/lib/anm.php` signs **outbound** calls to the payment provider. It
does not verify inbound gateway requests.

**Exposure.** Anyone who can reach the endpoint can assert any registered player's MSISDN and
transact as them — view balance and history, and **stake their Play Balance**. Withdrawal to
mobile money requires the account password and targets the player's own registered number, so
direct extraction is not the primary risk; causing loss is.

**Fix.** Validate the gateway signature and allowlist the aggregator's source IPs before the
MSISDN is trusted. This is `REQ-ID-004` and `REQ-SEC-009` in the target build.

---

## P-3 · CRITICAL · The engine kill switch does not cover USSD

`src/Wallet/GameEngineService.php:69–71` checks `engine.gameEngineEnabled` from `systemConfig`
on every play and returns 503 when disabled.

**`ussd/lib/game.php` has no equivalent check.** A repository-wide grep finds zero references to
`gameEngineEnabled` under `ussd/`.

**Exposure.** When operations disable the game — incident response, suspected exploit,
maintenance — the web channel stops accepting stakes immediately and **USSD keeps taking
real-money stakes with no gate at all**. "The game is off" is false for one entire channel.

---

## P-4 · HIGH · The two channels use different odds parameters today

The core game mathematics are a faithful port — multiplier table, decision arithmetic,
forced-loss construction, shuffle, ledger posting and business date are all identical between
`src/Wallet/GameEngineService.php` and `ussd/lib/game.php`.

**The configuration feeding that identical formula has forked.**

| Parameter | Web | USSD | Status |
|---|---|---|---|
| Source of truth | `systemConfig` table, read live per transaction | `ussd/config.php`, a static file read once at process boot | **Structurally divergent** |
| `dailyRevenueFloorPesewas` | 50,000 (code default) | 100,000 (`config.php:75`) | **Different today** |
| `houseWinThresholdPct` | 50, read live | 50 in `config.php:74`; code fallback **20** | Equal only by manual coincidence |
| `game.maxStakePesewas`, daily cap | Read live | Static snapshot | Same values today |

**Exposure.** `dailyRevenueFloorPesewas` raises the minimum `effectiveRevenue`, which widens the
payout ceiling before the engine flips to a forced loss. With the floor at 100,000 on USSD and
50,000 on web, **two players staking the same amount on the same game face different odds
depending on channel**, under identical shared revenue conditions.

Worse, both channels mutate the same `dailyRevenueSummary` row, so each influences the other's
outcomes while reading different parameters.

A comment at `ussd/config.php:66–68` records this as a known temporary state: *"fallbacks read
at boot; once SystemConfigService is copied in PR 4 we'll read from DB at runtime."* PR 4
appears never to have landed.

**Fix.** Point USSD at `systemConfig` so there is one source of truth. Until then, the two files
must be reconciled by hand and the discrepancy in the floor value corrected.

---

## P-5 · HIGH · One USSD withdrawal path has never worked

**`ussd/states/WdPlayPasswordState.php`**

The file is a **byte-identical copy** of `WdMomoPasswordState.php` — including its function
names. It declares `render_WD_MOMO_PASSWORD` and `handle_WD_MOMO_PASSWORD` rather than the
`WD_PLAY_PASSWORD` variants the registry maps it to.

```
lib/states.php:35   'WD_PLAY_PASSWORD' => 'WdPlayPasswordState.php'
lib/states.php:79   $fn = 'render_' . $stateName;      → 'render_WD_PLAY_PASSWORD'
lib/states.php:80   if (!function_exists($fn)) { throw new RuntimeException(...) }
WdPlayPasswordState.php:26   function render_WD_MOMO_PASSWORD(...)   ← wrong name
```

`function_exists('render_WD_PLAY_PASSWORD')` is false on every invocation, so the state throws
`RuntimeException: State WD_PLAY_PASSWORD has no render() function`.

**Exposure.** Every attempt to move money from the Winnings Balance to the Play Balance over
USSD fails at the password step. This is not an edge case — it is one of the two withdrawal
destinations offered by `WD_PICK_DESTINATION`, and it has presumably never worked in this
deployment. A copy-paste that was never finished.

**For the rewrite:** implement the intended behaviour, do not port the file. It is on the
DELETE list in the port manifest for a different reason, but the intended *flow* — the state
exists in the FSM and the menu offers it — must be built rather than inherited.

---

## P-6 · LOW · USSD rounds have an incomplete audit trail

`ussd/lib/game.php:513–524` omits `userAgent` from its `gameRound` insert; the web engine
records it (`GameEngineService.php:311`). The column is nullable so nothing fails — USSD rounds
simply always have NULL. No money or odds impact; a forensic gap only.

---

## Unverified — needs runtime or further inspection

- **Callback idempotency.** Whether a replayed provider callback can double-credit a deposit or
  double-process a withdrawal was not established. Examine `DepositService`, `WithdrawalService`
  and their USSD counterparts for uniqueness constraints and status guards.
- **`ussd/callbacks/momo.php`** — the second callback endpoint was not inspected. Given P-1,
  assume it is equally unverified until checked.
- **Player eligibility on web.** `ussd/lib/game.php:236–241` checks `accountStatus === 'active'`
  and `deletedAt === null`; no equivalent check appears in `GameEngineService::play()` or
  `GameController::play()`. Whether `AuthMiddleware` enforces it independently was not confirmed.
  If it does not, a suspended or soft-deleted player can still play on web.

---

## Suggested order

1. **P-1 and P-2** — both are unauthenticated trust boundaries on a live money system. The
   shared secret for P-1 already exists in configuration; wiring it up is small.
2. **P-3** — a missing safety control matters most precisely when it is needed.
3. **P-4** — correct the floor discrepancy immediately, then remove the fork.
4. Resolve the three unverified items above.

None of this depends on the refactor, and all of it reduces what the refactor has to carry.
