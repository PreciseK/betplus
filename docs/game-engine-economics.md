# Game Engine Economics — RTP, Profitability, and Bankroll-Protection Options

**Status:** working analysis, 2026-09-09. Numbers below are read from the current
codebase (seeders, preset libraries, config files). Nothing here is actuarially
certified — every prize table carries `actuarialCertRef = 'PENDING-ACTUARIAL-CERT'`
and the Lagos licence is `PENDING-LICENCE-NUMBER`.

---

## Part 1 — Current financial model per engine

### 1.1 Definitions

- **RTP** (return to player) = expected fraction of stake returned as prize over infinite play.
- **House edge** = `1 − RTP` = theoretical hold (GGR ÷ turnover), pre-variance, pre-cost.
- All money in the code is **kobo** (₦1 = 100 kobo). Figures below are converted to ₦.
- The **95% RTP ceiling** enforced at publication is a *maximum*. It guarantees an
  edge exists; it does **not** floor the edge, cap variance, or catch "edge too high".

### 1.2 BlackRed (`BLACKRED`)

Per-tier bet: player predicts B/R for 1–5 positions, each an independent fair 50/50,
and must get **all** predictions right. Each position count is its own bet type with
its own RTP — there is no blended table RTP; the realised hold depends on the mix of
tiers players actually bet.

Stakes: **₦100 min / ₦20,000 max** (`minStakeKobo` 10_000 / `maxStakeKobo` 2_000_000).

**Seeded / "good" preset** (`BR-NG-2026.1`, = approved Betplus design target):

| Positions | Win prob | Multiplier | RTP | House edge |
|----------:|---------:|-----------:|-------:|----------:|
| 1 | 1/2 = 50%     | 1.85x  | 92.50%  | 7.50%   |
| 2 | 1/4 = 25%     | 3.60x  | 90.00%  | 10.00%  |
| 3 | 1/8 = 12.5%   | 7.00x  | 87.50%  | 12.50%  |
| 4 | 1/16 = 6.25%  | 13.50x | 84.375% | 15.625% |
| 5 | 1/32 = 3.125% | 26.00x | 81.25%  | 18.75%  |

**"best" preset** (available to draft, not seeded): multipliers 1.40 / 2.80 / 5.60 /
11.20 / 22.40 → flat **70.00% RTP / 30.00% house edge** on every tier.

**"fair" preset**: multiplier = 2^positions → 100% RTP. Always fails the 95% gate by design.

### 1.3 BirdEscape / "Caged" (`BIRDESCAPE`)

Crash game. One crash point per shared round, a pure function of
`(seed, roundNumber, houseEdgeBasisPoints, dailyQuotas)`. Expected RTP is a direct
function of `houseEdgeBasisPoints` by construction of the engine formula — not
separately measured per config.

Stakes: **₦100 min / ₦10,000 max** (`minStakeKobo` 10_000 / `maxStakeKobo` 1_000_000).

**Seeded config:** `houseEdgeBasisPoints = 500` → **95.00% RTP / 5.00% house edge**.
Betting window 7s, post-crash interval 5s, growth 4000 ms per 1.00x step.

Presets: `fair` 0% edge / `good` 7.5% edge / `best` 30% edge (all inside the ceiling;
`good` matches BlackRed's lowest tier).

**Crash-point distribution** (before daily quota step-downs):

| Band | Probability | Multiplier range |
|------|------------:|------------------|
| Low    | 70% | 1.00x – 2.50x  |
| Mid    | 15% | 2.51x – 3.50x  |
| High   | 10% | 3.51x – 5.00x  |
| Tail   | 5%  | 5.01x – 35.00x |

**Payout caps:** 25.00x regular, **35.00x absolute** (also chosen so
`maxStake × maxMultiplier` cannot overflow `PHP_INT_MAX`).

**Daily tier quotas (rolling 24h)** — the engine steps the multiplier *down* a band
when a quota is exhausted:

- ≤ 2 rounds may exceed 25.00x (never above 35.00x)
- ≤ 5 rounds may land 20.00x – 25.00x
- ≤ 10 rounds may land 15.00x – 20.00x

### 1.4 Heritage (`HERITAGE`)

Per-ticket outcome draw over fixed tiers (probabilities in basis points, sum = 10 000).

Stakes: **₦100 min / ₦20,000 max** (`minStakeKobo` 10_000 / `maxStakeKobo` 2_000_000).

**Seeded table `HG-NG-2026.1`:**

| Tier | Probability | Multiplier | Outcome | RTP contribution |
|------|------------:|-----------:|---------|-----------------:|
| TIER_JACKPOT | 200 bp = 2.0%  | 25.00x | cash | 5000 bp = 50.0% |
| TIER_HIGH    | 600 bp = 6.0%  | 0.50x  | cash | 300 bp = 3.0%   |
| TIER_LOSS    | 9200 bp = 92.0% | 0     | none | 0               |
| **Total**    | 10 000 bp      |        |      | **5300 bp = 53.0%** |

**Gross modelled RTP ≈ 53.0% → house edge ≈ 47.0%.** Net of 5% resident WHT ≈ 50.3% RTP.

> ⚠ **Discrepancy to resolve with Finance/actuary.** The seeder comment claims
> "Modelled RTP 81.6% (5000 + 3000 + 160bp)" — that is the PRD §9.4 *four*-tier design
> (with a `TIER_SECOND_CHANCE` draw-entry tier at 0.0160 cost per stake and a
> higher-value `TIER_HIGH`). The table actually seeded has **three** tiers and
> computes to **53%**, i.e. a 47% edge — roughly 2.5× the BlackRed pos-5 tier and far
> below the PRD design. `TIER_HIGH` at `multiplierHundredths = 50` (0.50x, "half stake
> back") is the likely divergence point. The 95% publication gate is a ceiling, so 53%
> passes it cleanly; the gate does not flag an over-aggressive edge.

### 1.5 Withholding tax (not house margin)

`config/tax.php`, ruleset `LAG-WHT-2026.1`, basis = **gross prize**:

| Player class | Rate |
|--------------|-----:|
| Resident     | 500 bp = 5%  |
| Non-resident | 1500 bp = 15% |

WHT is collected from winner prizes and remitted — it is **cash-flow-positive on
timing but not retained margin**. It does widen net-of-tax RTP figures on paper
(e.g. Heritage 53.0% gross → ~50.3% net-of-WHT).

### 1.6 Payout float thresholds

`config/payout.php` — **all placeholder env defaults, not a modelled forecast**:

| Key | Default | Note |
|-----|--------:|------|
| `manual_review_threshold_kobo`   | ₦1,000,000 | payout above this needs manual review |
| `expected_daily_payout_kobo`     | ₦5,000,000 | placeholder; needs Finance input on real volume |
| `largest_theoretical_prize_kobo` | ₦520,000   | ₦20,000 max stake × 26.00x (BlackRed pos-5) |

`FloatService` alert tiers on the OPay disbursement-rail balance:

| State | Trigger | Action |
|-------|---------|--------|
| `ok`       | ≥ 3× expected daily payout | — |
| `warning`  | < 3× expected daily payout | log, notify Finance |
| `critical` | < 1× expected daily payout | page Finance + Ops, top up |
| `halt`     | < largest theoretical prize | suspend auto-disbursement, page execs |

### 1.7 Profitability summary

Theoretical hold (GGR ÷ turnover) at **seeded** config:

| Engine | Theoretical hold | Notes |
|--------|-----------------:|-------|
| BirdEscape | **5.0%** | flat, by config |
| BlackRed   | **7.5% – 18.75%** | depends on tier mix of real bet volume |
| Heritage   | **47.0%** | per seeded table — flagged as inconsistent with PRD/design |

**Not in the codebase** (needed for a real P&L): live turnover / bet-volume mix,
operating cost, bonus/promo cost, PSP fees, chargebacks, seed-capital size, and any
reserve. All figures above are modelled, pre-variance, pre-cost.

---

## Part 2 — Where exposure is bounded today

| Mechanism | Location | Caps |
|-----------|----------|------|
| 95% RTP ceiling, publication-blocked | `HeritagePrizeTablePublicationGate`, `CrashConfigPublicationGate` | Long-run margin ≥ 5% by construction; needs `actuarialCertRef` to publish |
| Per-bet stake caps | `PlaceCrashBet` + `LimitsService`, velocity, protection, registry | Single-bet size; per-player exposure |
| Max multiplier cap | `BirdEscapeEngine` (25x regular / 35x absolute) | Single crash payout; also stops int overflow |
| Daily tier quotas (crash) | `BirdEscapeEngine` + `RoundLifecycleService` | Frequency **and** size of high rounds per 24h — engine steps multipliers down |
| Float gauge + disbursement halt | `FloatService` | Watches payout-rail balance; halts auto-disbursement below the largest-prize floor |

The crash daily tier quota system is **already a crude dynamic-balancing mechanism** —
it caps the frequency and size of high-payout events per day regardless of RNG. This
is more protection than a plain fixed-RTP operator has.

## Part 3 — Gaps relative to a dynamic-pool + reserve-fund model

1. **No aggregate per-round liability cap.** `RoundLifecycleService::tickFlight`
   batch-settles every PLACED bet; nothing bounds `Σ(stake × crashMultiplier)` for a
   single round. Daily quotas limit how *often* a high round happens, not the *total*
   staked on any one popular round. This is the unbounded tail.
2. **No dynamic weighting by pool / liability state.** The crash distribution is fixed
   (70/15/10/5), modulated only by daily count quotas. It does not look at live
   staking on the current round or at cumulative daily GGR/loss. Heritage's tier draw
   is fully static.
3. **No reserve fund / profit siphon.** Nothing moves a percentage of daily profit to
   a segregated account. `FloatService` is the closest thing and it is only a balance
   thermometer with a hardcoded floor and "page a human" as the backstop.
4. **`commitmentDigest` is published at round start, before betting**
   (`RoundLifecycleService::startRound`). Any per-wager, liability-reactive change to
   the crash point breaks the current commit-reveal fairness guarantee — the concrete
   certification blocker for full dynamic weighting.
5. **Heritage has no quota system at all** — tiers are fully static (`tiers.py`). On
   per-ticket variance it is the more exposed engine, and the per-round-cap idea does
   not map (no shared round). Its lever is a running daily jackpot-count cap or a
   pool-funded jackpot.

## Part 4 — Advantages of the proposed model (dynamic pool balancing + reserve fund)

### 4.1 Dynamic pool balancing (per-wager payout-weight adjustment)

- **Bounded instantaneous liability** — odds tighten automatically as the pool drains,
  so a variance spike cannot wipe the float. Fixed RTP just eats the loss and waits
  for regression to the mean.
- **Lower revenue variance** — daily/weekly GGR lands in a band instead of a spread.
  This is what makes cash flow, payroll, and regulator/investor reporting predictable
  ("revenue balancing" vs praying to the RNG).
- **Self-correcting** — no manual dial-turning when a game runs hot.
- **Lets you advertise a more generous RTP safely** — the safety valve means headline
  RTP can rise without a proportional rise in ruin risk. Marketing advantage.
- **Makes pool-funded jackpots viable** — big top prizes become fundable because
  payout probability scales with accumulated contributions.

**The catch:** per-wager weight changes mean you no longer have fixed, publishable
odds. That collides with provably-fair guarantees and with RNG certification
(GLI-style) and NLRC / LSLGA disclosure expectations unless the *mechanism itself* is
disclosed and certified. Sophisticated players notice a game that "tightens after a
big win" — reputation risk. It is also a hot-path recalculation that must stay
deterministic and auditable (seed + pool-state hash).

### 4.2 Reserve fund (siphon a % of daily profit into a segregated account)

- **Seed capital never gets touched** — the operating float takes the hits, the
  reserve backstops the float, seed stays clean.
- **Regulatory alignment** — segregated funds covering outstanding liabilities
  (player balances + open bets) is often a licence condition anyway. This formalises
  it for audit / renewal.
- **Guaranteed payout solvency** — winners can always be paid mid-drawdown, which
  protects the licence and the brand.
- **Countercyclical discipline** — profit is not all withdrawable; the buffer fattens
  in good periods to fund bad ones.
- **Cleaner accounting** — separates "banked profit" from "house money at risk", so
  real profit is distinguishable from being up on variance.

**The catch:** it does not *reduce* variance, it *survives* it — insurance, not a fix.
A fixed percentage is a guessed number; size it from a Monte Carlo on the real outcome
distribution / max historical drawdown, and keep it a tunable knob.

### 4.3 Why both beat RTP-only

Pool balancing cuts the frequency and depth of drawdowns; the reserve survives the
ones that still get through. Defence in depth. Together they let you run leaner seed
capital, a higher effective RTP, and bigger jackpots than static RTP alone would
safely permit — and they give a stronger regulatory story: certified game math *plus*
provable ability to pay.

## Part 5 — Recommendation, priority-ordered

1. **Per-round aggregate liability cap — build this first.** Highest value, lowest
   risk slice of "dynamic balancing". In `PlaceCrashBet`'s commit transaction,
   accumulate `Σ(stake × max cap)` for the live round; once it crosses a configured
   ceiling (e.g. N× expected round GGR, or a fixed kobo cap), reject new bets with a
   `ROUND_EXPOSURE_CAP` code (same shape as the existing `ROUND_NOT_ACCEPTING_BETS`).
   Bounds the single-round tail the daily quotas miss. It is a bet-acceptance limit,
   not an odds change — fully disclosable, no re-certification.
2. **Reserve fund — clear yes, genuinely missing.** `FloatService` already gestures at
   "can we always pay the largest prize" but with a static config floor and a human
   page as backstop. Formalise it: a new segregated `LedgerLine` account plus a daily
   job that moves a % of net daily profit in. Sits naturally next to `FloatService` /
   `TurnoverService`. Size the % from a Monte Carlo on the actual crash distribution +
   Heritage tiers + measured max drawdown — not a guessed number. Leave the % a config
   knob.
3. **Per-wager dynamic weighting — check before building; you need it least.** The
   daily quotas already do the coarse version. True per-wager liability-reactive
   weighting means the crash point stops being a pure function of
   `(seed, roundNumber, houseEdge, quotas)` and starts depending on live staking —
   forcing either resolve-after-betting-closes with a new commit-reveal, or a
   disclosed liability term folded into the certified formula. Both are real work plus
   NLRC / LSLGA re-certification. With #1 and #2 in place the marginal variance win is
   small. YAGNI until uncapped multipliers or pooled jackpots are added.

## Part 6 — Immediate follow-ups (independent of the model choice)

- Resolve the **Heritage 53% vs 81.6% RTP discrepancy** (§1.4) — confirm intended
  `TIER_HIGH` multiplier and whether the second-chance tier is in scope for launch.
- Replace the **placeholder `config/payout.php` values** with Finance's real
  expected-daily-payout and largest-prize figures.
- Obtain real **actuarial certification** for all three prize tables before any
  production publish (all currently `PENDING-ACTUARIAL-CERT`).
