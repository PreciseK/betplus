# Model 4 — Pari-Mutuel Pool — Design Spec

**Status:** approved for planning
**Date:** 2026-09-15
**Origin:** `docs/superpowers/specs/2026-09-13-admin-gaming-economics-models-design.md` §9
explicitly deferred Model 4 to its own phase and its own design spec — this is that
spec. Phases 1 (Fixed RTP, Balanced Hybrid) and 2 (Daily Loss-Stop, reserve fund) are
already built and merged to `master`.

## 1. Goal

Give BlackRed, Heritage, and Caged-USSD a fourth economics model — a pari-mutuel pool,
where a batch of players' stakes for a shared draw window are pooled, a single drawn
outcome is compared against every ticket in that pool, and the pool (minus a house
rake) splits among matching tickets proportional to their stake. This is genuinely new
architecture: none of the three engines have a "pool stakes, wait, draw once, split
proportionally" mechanism today — all three settle every ticket instantly, on its own
independent RNG roll, against a fixed-odds prize table.

## 2. Scope

**In scope:** BlackRed, Heritage, Caged-USSD.

**Explicitly out of scope:** BirdEscape (Caged-web). Its continuous shared-round crash
mechanic — bet anytime during a betting window, cash out anytime before the crash —
has no natural "pick the right shared outcome" shape a pool can split winners against.
`EconomicsModelStrategyFactory::forCrashGame()` never routes `PARI_MUTUEL_POOL`
anywhere but its existing `FixedRtpStrategy` fallback — permanently, not as a
Phase-3-stub-until-later placeholder like today's code comments say. That fallback
comment is stale as of this spec and gets corrected in the implementation.

## 3. Decisions (from brainstorming discussion)

| Question | Decision |
|---|---|
| Which games get Model 4? | BlackRed, Heritage, Caged-USSD only. BirdEscape never gets it (see §2). |
| What closes a pool? | A **fixed schedule** — a pool opens at a scheduled time and closes at a scheduled time, same shape as Heritage's existing Second-Chance/Monthly-Draw jobs. No ticket-count threshold, no dynamic sizing. |
| Does purchasing become a wait? | **Yes.** A new `PENDING_DRAW` ticket status. Purchase returns immediately with a draw time, not a result — same principle `CreateTicket` already uses for withholding win/loss inside one request, extended across the draw wait. Settlement reuses the existing reveal endpoint + SMS notification path (the same shape Heritage's Second-Chance already uses for a delayed result). |
| BlackRed win mechanic | One winning B/R sequence is drawn **per pick-length** (a length-2 pool is a separate pool from a length-3 pool). Every ticket that predicted that exact sequence at that length splits the pool (net of rake) proportional to stake. Everyone else's stake stays in the pool and funds the winners. |
| Heritage win mechanic | **Keeps its existing tiered structure** as separate sub-pools inside one draw: the draw picks one winning 5-position combination; the net pool splits across match-count tiers (5/5, 4/5, 3/5, 2/5) by a configured bps allocation, and within each tier, that tier's share splits proportional to stake among everyone who hit exactly that match count. |
| Caged win mechanic | **Threshold, not exact-match** — preserves today's "at least my target" semantics. One escaped-bird count is drawn per pool; winners are every ticket whose target was met or beaten by it, splitting the net pool proportional to stake. |
| House rake | A **configurable `rake_bps` param** on the `PARI_MUTUEL_POOL` config, same shape as `BALANCED_HYBRID`'s `kelly_factor_basis_points`. `GameEconomicsModelGate` rejects any value below **1200bp (12%)** — the mirror image of the platform's 8800bp (88%) RTP ceiling, because a pari-mutuel pool pays out exactly `pool × (1 − rake%)` every draw with no per-draw variance in the aggregate (only in who wins), so the ceiling is enforced as a rake floor instead of a per-ticket multiplier×probability check. |
| No winners in a pool/tier | **Rolls over** to that same pool/tier's next draw window — classic lottery-jackpot behavior. A stake is never silently absorbed by the house beyond the rake; it either pays a winner now or pays a (probably bigger) winner later. |

## 4. Data model

Two new tables, plus one new `Ticket` status. Column naming matches the codebase's
existing camelCase convention (`gameDailyLedger`, `gameEconomicsConfig`).

```text
poolDraw
  id, gameCode, poolKey (nullable — BlackRed's pick-length 1-5; null for Heritage
    and Caged, which run one pool per window with no sub-key),
  status (open | closed | settled),
  opensAt, closesAt,
  drawnOutcomeJson (the shared result once drawn — e.g. {"sequence": "BR"} for
    BlackRed, {"positions": [2,4,5,7,9]} for Heritage, {"escapedBirds": 3} for Caged),
  seedRef (FK to the fairnessSeed row SeedIssuer::issue() produced for this draw —
    same fairness-chain commitment scheme instant tickets already use),
  grossStakedKobo, rakeKobo, netPoolKobo,
  rolloverInKobo (carried in from the prior unwon draw for this poolKey/tier set),
  drawnAt, settledAt, createdAt, updatedAt

  unique (gameCode, poolKey, opensAt)
  index (gameCode, poolKey, status) — the lookup CreateTicket/CreateHeritageTicket/
    CreateCagedTicket use to find "the currently open pool to join"

poolEntry
  id, poolDrawId, ticketId, playerId,
  predictionJson (the ticket's own prediction — same shape as Ticket.predictionJson,
    duplicated here so settlement never has to join back to ticket for the hot path),
  stakeKobo,
  matchTier (nullable until settlement; Heritage's match count 2-5, null for
    BlackRed/Caged which have no tiers),
  won (nullable until settlement), payoutKobo (nullable until settled),
  createdAt

  index (poolDrawId, matchTier) — settlement's per-tier winner scan
```

Heritage's per-tier rollover means `rolloverInKobo` alone isn't enough once tiers
exist — Heritage's `poolDraw` row carries a `tierRolloverJson` field instead
(`{"5": 120000, "4": 0, "3": 45000, "2": 0}`, kobo per tier), and BlackRed/Caged
(no tiers) use the plain scalar `rolloverInKobo`. Both fields exist on every
`poolDraw` row; each game's settlement service reads only the one it needs.

`Ticket.status` gains `PENDING_DRAW`, sitting between `CREATED` and `SETTLED` in the
existing status progression. A ticket in `PENDING_DRAW` has no `TicketOutcome` row yet
— one is written at settlement, same as today, just later.

## 5. Purchase flow

`EconomicsModelStrategyFactory::forTicketGame()` still resolves and runs
`assertAcceptable()` first, unchanged — the acceptance gate (stake limits, KYC,
protection, registry) doesn't change based on settlement shape. What changes is
**after** acceptance: today, `CreateTicket`/`CreateHeritageTicket`/`CreateCagedTicket`
call their engine's `resolve()` synchronously and settle the wallet in the same
transaction. When the game's active model is `PARI_MUTUEL_POOL`, they skip engine
resolution entirely — there is no per-ticket RNG roll to make, because the ticket's
fate depends solely on comparing its prediction to the pool's one shared drawn
outcome, which doesn't exist yet.

Instead: reserve the stake (unchanged — `WalletService::reserveStake` still moves
funds to `SUSPENSE` immediately, so the player's balance reflects the wager right
away), find-or-create the currently open `poolDraw` for `(gameCode, poolKey)` via a
new `PoolDrawService::currentOrNextPool()` (mirrors `RoundLifecycleService::
currentOrNextRound()`'s exact shape and locking), insert a `poolEntry`, set the ticket
`status = PENDING_DRAW`, and return:

```json
{ "reference": "...", "status": "pending_draw", "draw_at": "2026-09-15T13:00:00Z" }
```

No `won`/`net_credit_kobo` fields — the reveal endpoint (existing, per-game) returns
`{"status": "pending_draw"}` until settlement runs, then the same shape it always has
plus `pool_total_kobo` and `your_share_kobo` for transparency.

## 6. Draw + settlement

A new scheduled command per game (`economics:draw-blackred-pool`, `economics:draw-
heritage-pool`, `economics:draw-caged-pool`), registered in `routes/console.php`
alongside the existing `Schedule::command(...)` entries, running at each game's pool
cadence. Each command finds every `poolDraw` row past `closesAt` still `status=open`
for its game, and for each:

1. **Close** the pool (`status=closed`) — a conditional `UPDATE ... WHERE status =
   'open'` exactly like `BirdEscapeSettlement`'s single-UPDATE concurrency guard, so
   two overlapping command runs can't both draw the same pool.
2. **Issue one `SeedIssuer::issue()` seed** and draw the shared outcome via a new
   engine method — `BlackRedEngine::drawPoolOutcome(seedHex, length)`,
   `HeritageEngineClient::drawPoolOutcome(seedHex)` (HTTP call, same remote-engine
   shape Heritage's per-ticket resolve already uses), `CagedEngine::
   drawPoolOutcome(seedHex)` — each a new method alongside the existing per-prediction
   `resolve()`, since drawing an outcome with no prediction to check against is a
   different operation, not a variant of the existing one.
3. **Compare every `poolEntry`** for that `poolDraw` against the drawn outcome
   (BlackRed/Heritage: exact match; Caged: threshold), computing `matchTier` for
   Heritage.
4. **Compute payouts**: `netPoolKobo = grossStakedKobo × (10000 − rake_bps) / 10000`
   (BlackRed/Caged) or per-tier via the configured allocation (Heritage); rollover
   added into whichever pool/tier had zero winners this draw is carried onto the
   `poolDraw` row this settlement creates for the *next* window, not spent this draw.
   Each winner's `payoutKobo = tierNetPoolKobo × (entryStakeKobo / tierTotalStakeKobo)`,
   truncated to the kobo (integer division). The truncation remainder can't be known
   until every winning entry in a tier is computed, so it isn't a per-ticket
   quantity — it's folded into the same single `HOUSE_REVENUE` ledger post step 6
   makes for the rake, one post per pool (or per tier for Heritage), so the pool's
   ledger entries always balance exactly (mirrors how `TaxEngine`/withholding
   rounding is already handled elsewhere in this codebase — never leave an
   unbalanced kobo, and never post two separate house-revenue entries for one
   settlement when one suffices).
5. **Settle**, one ticket at a time, inside a transaction per ticket (not one giant
   transaction for the whole pool — mirrors `BirdEscapeSettlement`'s per-bet
   transaction shape, so one slow/failing settle doesn't block the rest of the pool):
   write `TicketOutcome`, call `WalletService::settleWin`/`settleLoss` (existing,
   unchanged), flip `Ticket.status = SETTLED`, `poolEntry.won`/`payoutKobo`.
6. **Rake** posted to `HOUSE_REVENUE` via one `WalletService::post()` call per pool
   (not per ticket) referencing the `poolDraw` row.
7. **Open the next pool** for that `(gameCode, poolKey)` immediately, carrying any
   rollover forward, then fire `notifyTicketSms` for every settled ticket — same
   existing call, same existing template, just invoked from this new job instead of
   from inside the purchase request.

`DispatchPrizePayoutJob` (winnings payout) fires exactly as it does for instant
tickets today — nothing about the payout-to-bank/OPay path changes, only what decides
whether a ticket won.

## 7. RTP ceiling reconciliation

Every other model's publication gate checks that a *designed* prize table or
multiplier can't exceed 8800bp aggregate RTP — a ceiling on the maximum the house
could ever pay out. Pari-mutuel has no such design-time RTP at all: whatever isn't
raked always reaches winners in full, every single draw, by construction. So
`GameEconomicsModelGate`'s `PARI_MUTUEL_POOL` validation is the mirror of every other
model's:

```php
if ($rakeBps < (10_000 - RtpCeiling::BASIS_POINTS)) {  // < 1200
    return ["rake_bps {$rakeBps} is below the minimum {$minRakeBps} required to keep RTP at or under " . ...];
}
```

Heritage's tier-allocation bps map must also sum to exactly 10,000 (validated the
same way `CagedEngine::validateTiers()` already validates probability sums elsewhere
in this codebase) — a partial allocation would silently leak pool value nowhere.

## 8. Admin UI

No new screen. The existing `GameEconomicsConfigConsole` (`back-office/game-
economics`) already renders a conditional param field per selected model
(`kelly_factor_basis_points` for `BALANCED_HYBRID`, `daily_loss_cap_kobo` for
`DAILY_LOSS_STOP`) — `PARI_MUTUEL_POOL` adds `rake_bps` (number input, same shape),
and Heritage specifically also needs the four tier-allocation bps fields. The
propose/approve maker-checker flow, audit trail, and gate-error display are all
already generic and need no changes.

## 9. Testing

- **Engine unit tests**: each game's new `drawPoolOutcome()` method — deterministic
  given a seed, matches the existing per-ticket engines' seeded-RNG test pattern.
- **`PoolDrawService` tests**: pool find-or-create/locking (mirrors
  `RoundLifecycleServiceTest`'s concurrency tests), pool closing idempotency.
- **Settlement tests, per game**: single winner splits 100%; multiple winners split
  proportional to stake (exact kobo amounts, including the truncation-remainder-to-
  house-revenue case); zero winners rolls the full net pool forward; Heritage's
  four-tier allocation with a mix of tiers won/unwon in the same draw; ledger
  debits=credits after settlement (same assertion shape `BlackRedTicketTest` already
  uses).
- **Gate tests**: `rake_bps` boundary (1199 rejected, 1200 accepted); Heritage's
  tier-allocation-must-sum-to-10000 check.
- **Purchase-flow tests, per game**: `PARI_MUTUEL_POOL` active → purchase returns
  `pending_draw` with no `won` field, ticket status is `PENDING_DRAW`, reveal returns
  `pending_draw` until settlement runs.

## 10. Out of scope for this phase

- BirdEscape (see §2 — permanently out of scope for this model, not deferred).
- Any admin-facing pool monitoring dashboard (live pool size, time-to-draw) — the
  existing per-game descriptor/config screens are sufficient for launch; a richer
  operator view is a reasonable follow-up once real usage data exists.
- Consolidating pool settlement into `GameDailyLedgerService`'s daily P&L tracker —
  `GameDailyLedgerService::recordSettlement()` still gets called per settled ticket
  from inside step 5 above (so Daily Loss-Stop and the reserve-fund siphon keep
  working unmodified for a game running Model 4), but no new reporting surface is
  built specifically for pool history beyond `poolDraw`'s own rows.
