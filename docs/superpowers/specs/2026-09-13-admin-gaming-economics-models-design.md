# Admin Gaming Economics Models — Design Spec

**Status:** approved for planning
**Date:** 2026-09-13
**Origin:** `docs/Admin_Gaming_Economics_Models_Specification.md` (business/regulatory framing) —
this spec is the technical design that implements it.

## 1. Goal

Give admins a way to select, per game, which of four economics models governs house
margin and bankroll risk, with a standardised 88% RTP ceiling platform-wide, applied
uniformly across BlackRed, Heritage, and Caged (both its web and USSD engines).

## 2. Current-state findings (why this isn't a simple config flag)

- **"Caged" is two different math engines under one brand.** `gameCode=CAGED`
  (`CagedController`, `CreateCagedTicket`, `CagedEngine`) is an in-process, discrete
  "escape count" ticket engine (1–5 tiers, instant resolution) — this is what the USSD
  channel (and any web ticket-mode UI) calls. `gameCode=BIRDESCAPE`
  (`BirdEscapeController`, `RoundLifecycleService`, `CrashConfig`) is the original
  continuous shared-round crash engine — this is the web crash game. They are
  registered as separate `GameRegistry` rows today and must be designed for as two
  engine shapes, not one.
- **Three engine shapes overall**, each with its own config entity and publication
  gate, all following the same maker-checker convention (draft → gate validates
  inline → `ReviewableChangeController` approval → `*PublishApplier` publishes →
  `AuditLog`):
  - **In-process ticket engines** (BlackRed, Caged-USSD): `PrizeTable` + tiers,
    resolved via `PrizeTableResolver`, gated by `PrizeTablePublicationGate` /
    `CagedPrizeTablePublicationGate`.
  - **Remote-microservice ticket engine** (Heritage): same `PrizeTable` shape, but
    resolution is an HTTP call to `apps/engine-heritage` (Python) via
    `HeritageEngineClient`, gated by `HeritagePrizeTablePublicationGate`.
  - **Continuous shared-round crash engine** (Caged-web/BirdEscape): `CrashConfig`
    (single `houseEdgeBasisPoints` scalar + timing), driven by `RoundLifecycleService`,
    gated by `CrashConfigPublicationGate`.
- **RTP ceiling (9500bp/95%) is duplicated** across all publication gate classes, and
  real seeded RTPs are already inconsistent: BlackRed 70–92.5%, Heritage ~53% (a
  known, documented discrepancy against its own PRD), BirdEscape/Caged-web 95%.
- **`GameRegistry`** fields (status, channels, states, stake limits) apply immediately,
  no gate — the one exception to the maker-checker convention in this codebase.
- **No daily, per-request-cheap P&L tracking exists across all engines.**
  `DailySummaryService::forDay()` sums `Ticket`/`TicketOutcome` only — it has no
  equivalent for `CrashBet`/`CrashRound`, and it's a query-on-demand report, not a
  live counter.
- Existing hooks Model 1 and Model 3 attach to: `FloatService` (OPay balance floor
  gauge), `TurnoverService`, `LimitsService`.

## 3. Decisions (from clarifying discussion)

| Question | Decision |
|---|---|
| What does "RTP should be 88%" mean? | A new **hard ceiling**, 8800bp, replacing 9500bp in every publication gate, platform-wide. Also the **fixed target** for Model 2 specifically (not a slider). |
| Model 4 (Pari-Mutuel Pool) scope | **Full build**, but as a **later phase** (Phase 3) — genuinely new pooling/settlement architecture the ticket games don't have today. |
| Does a model switch need approval? | **Yes — maker-checker**, same as publishing a `PrizeTable`/`CrashConfig`. A model switch is a financial-policy change. |
| Which admin levers ship first for Models 1 & 3? | Follow `docs/game-engine-economics.md`'s existing priority: (1) per-round/per-ticket exposure cap, (2) reserve fund, (3) per-wager dynamic weighting stays **out of scope** (YAGNI). |
| Runtime enforcement shape | **Per-model strategy classes** implementing a shared interface — matches this codebase's convention of small, single-purpose domain classes, over one large central branching service. |

## 4. Data model & resolution

New entity, same lifecycle shape as `PrizeTable`/`CrashConfig`:

```text
game_economics_config
  id, gameCode, version, status (draft|published),
  activeModel (FIXED_RTP | BALANCED_HYBRID | DAILY_LOSS_STOP | PARI_MUTUEL_POOL),
  paramsJson,            -- validated per-model param bag
  actuarialCertRef, effectiveAt, publishedAt
```

No `stateCode` column, unlike `PrizeTable` — the economics model is a house-side
financial policy per game, not a per-jurisdiction prize table, and every game today
resolves against a single stubbed jurisdiction (`jurisdiction.stub_state_code`)
anyway. If multi-state jurisdiction support lands later, this is the one entity that
would need a scoping column added — noted here rather than left implicit.

One JSON param bag rather than a column per possible knob across 4 models (most are
null for 3 of 4 models at any time). A `GameEconomicsParams` value object per model
types/validates the bag at the domain boundary:

- `BalancedHybridParams { kellyFactor, reserveSiphonBps }`
- `DailyLossStopParams { triggerKobo, maxMultiplierCeilingHundredths, hourlyDrawdownThresholdKobo }`
- `FixedRtpParams {}` (empty — 88% is enforced by the gate, not a param)
- `PariMutuelPoolParams { rakeBps, minPoolGuaranteeKobo, rolloverBps }` (schema only in
  Phase 1/2; enforced starting Phase 3)

**Resolution:** `EconomicsConfigResolver::resolveFor(string $gameCode): ?GameEconomicsConfig`,
structurally identical to `CrashConfigResolver`/`PrizeTableResolver` — always reads the
latest `published` row. This is deliberately how "a switch takes effect at the next
round/ticket boundary" is achieved with **no new staging layer** (the original spec
doc's `engine:pending_model_switch` Redis idea is dropped) — the resolve-fresh-at-use
pattern every engine already follows gives that property for free.

**Publish workflow:** `GameEconomicsConfigController` (BackOffice draft CRUD + inline
gate preview), `GameEconomicsModelGate` (validates param shape per model + the shared
8800bp ceiling), `GameEconomicsConfigPublishApplier` wired into the existing
`ReviewableChangeController` — one more `change_type`, identical pattern to
`CrashConfigPublishApplier`/`PrizeTablePublishApplier`.

## 5. Model mechanics, mapped to engine shape

```php
interface EconomicsModelStrategy
{
    /** @throws EconomicsRejection */
    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): EconomicsDecision;
}
```

`EconomicsContext` is a small DTO carrying what differs by engine shape (ticket games
pass the selected tier/target; the crash engine passes the live round's current
aggregate exposure). Resolved via `EconomicsConfigResolver` +
`EconomicsModelStrategyFactory::for($config)`, injected at the same call sites
`LimitsService`/`VelocityService` already use today: `CreateTicket`,
`CreateCagedTicket`, `PlaceCrashBet`, and `RoundLifecycleService::startRound()` for the
round-level side of Model 1's crash-engine cap.

| Model | Ticket engines (BlackRed, Heritage, Caged-USSD) | Crash engine (Caged-web) |
|---|---|---|
| **2 — Fixed RTP** | No-op strategy. 8800bp ceiling enforced at publish time only. | Same no-op; existing daily tier-quota stepdown unchanged. |
| **1 — Balanced Hybrid** | Per-ticket cap: `maxStake = k × currentFloat` (via `FloatService`), checked pre-acceptance. Reserve-siphon (Phase 2) skims a bps cut of net daily win into a segregated `LedgerAccount`. | Per-**round** aggregate cap: sums `Σ(stake × cap)` for the live round via a new `exposureKobo` counter on `CrashRound`/`RoundStateStore`, incremented in `PlaceCrashBet`'s existing commit transaction — no new locking model. Rejects new bets past the ceiling. Same reserve-siphon. |
| **3 — Daily Loss-Stop** | Checks `GameDailyLedger` (§6) pre-acceptance; past threshold: shrink effective max stake → restrict to lower-multiplier tiers only → hard-reject new tickets. | Same tracker check; past threshold: shrink Kelly-style cap → cap crash ceiling to 5–10x for new bets → suspend new bets. Reuses existing `RoundLifecycleService` daily tier-quota machinery, with an adjustable trigger replacing the current fixed 2/5/10-round bands. |
| **4 — Pari-Mutuel Pool** | Selectable now; strategy is a **stub that behaves like Model 2** until Phase 3. | Same stub-for-now treatment. |

Rejections surface as the existing `TicketEligibilityException`-style error-code
pattern (`ROUND_EXPOSURE_CAP`, `DAILY_LOSS_STOP_ACTIVE`), matching
`ROUND_NOT_ACCEPTING_BETS` — never a generic 500.

## 6. Daily P&L tracker (new)

`GameDailyLedger` — one row per `(gameCode, date)`, incrementally updated in the same
transaction each engine already commits settlement in (`CreateTicket`,
`CreateCagedTicket`, `BirdEscapeSettlement::settleCashout/settleLoss`):

```text
game_daily_ledger
  gameCode, ledgerDate, grossStakesKobo, grossPrizesKobo,
  netGgrKobo (generated: grossStakesKobo - grossPrizesKobo)
```

`GameDailyLedgerService::recordSettlement($gameCode, $stakeKobo, $prizeKobo)` performs
an atomic upsert-increment (`UPDATE ... SET grossStakesKobo = grossStakesKobo + ? ...`)
— no read-then-write race, same safety property as `BirdEscapeSettlement`'s conditional
UPDATE. Model 3's strategy reads this with one indexed lookup before accepting a
stake — cheap enough for the crash engine's 200+ rounds/hr volume.

Consolidating `DailySummaryController`'s reporting to read from this same ledger is a
reasonable follow-up but explicitly **out of scope** here, to keep this effort's
surface area bounded.

## 7. RTP ceiling change

Single shared constant, `RtpCeiling::BASIS_POINTS = 8800`, replacing the duplicated
`9500` literal in `PrizeTablePublicationGate`, `HeritagePrizeTablePublicationGate`,
`CagedPrizeTablePublicationGate`, and `CrashConfigPublicationGate`. Same ceiling for
every game. This also forces a resolution of Heritage's currently-documented 53% RTP
discrepancy — any future Heritage prize table publish is rejected until it's within
88%.

Model 2's admin UI shows a fixed, non-editable 88% target (no RTP slider) — the gate
enforces it exactly, not "at or under."

## 8. Admin UI

New BackOffice screen, `back-office/game-economics`, alongside the existing
`back-office/game-configurations`:

- Per-game card (BlackRed, Heritage, Caged) showing current published model + params +
  status (draft pending approval / published / rejected).
- "Propose model switch" form: pick model, fill that model's params (fields driven by
  selected model), submit → `GameEconomicsConfig` draft, gate errors shown inline
  immediately — same UX as `CrashConfigController`/`GameRegistryController` today.
- Routes into the existing `ReviewableChangeController` approval queue — one more
  `change_type`, reusing the existing approval UI.
- Audit trail: identical `AuditLog` shape (actor, before/after, IP, user agent) as
  every other gate — satisfies the LSLGA export requirement with no new mechanism.

## 9. Phased rollout

- **Phase 1 — Foundation.** `GameEconomicsConfig` schema, resolver, gate, BackOffice
  controller, maker-checker wiring, admin UI. 8800bp ceiling replaces 9500bp
  everywhere. Model 2 (no-op strategy) and Model 1's exposure-cap half (per-ticket
  formula + per-round aggregate counter) ship together.
- **Phase 2 — Reserve fund + Daily Loss-Stop.** `GameDailyLedger` + Model 3's
  progressive throttle (both engine shapes) + Model 1's reserve-siphon into a
  segregated `LedgerAccount`.
- **Phase 3 — Pari-Mutuel Pool.** Real pooling/settlement architecture for Model 4 —
  own follow-up spec when this phase starts (needs round/draw batching the ticket
  games don't have today).

Phase 1 is the immediate implementation-plan scope. Phase 2 gets its own plan once
Phase 1 lands and is verified; Phase 3 gets its own design spec before it gets a plan
at all.

## 10. Testing

- Unit tests per strategy class — boundary cases (at cap, just under, just over) for
  each model.
- Gate tests per model's param validation, mirroring existing
  `CagedPrizeTablePublicationGateTest`-style tests.
- Feature tests for the full draft → gate → approve → publish → resolve cycle, per
  engine shape.
- Concurrency test for `GameDailyLedgerService`'s upsert, mirroring the existing
  `CrashRound` conditional-UPDATE test style, proving no lost updates under parallel
  settlements.

## 11. Explicitly out of scope (this effort)

- Real pari-mutuel pooling/settlement (Phase 3, separate spec).
- Per-wager dynamic odds-weighting (YAGNI per existing analysis doc).
- Consolidating `DailySummaryController`'s reporting onto `GameDailyLedger`.
- A real paging/alerting integration for loss-stop breaches (log-level only, same
  precedent as `FloatService`'s "page Finance" comment).
