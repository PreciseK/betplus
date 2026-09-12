# Executive Comparison: Caged USSD Game Mechanics

**Document Type:** Strategic Decision & Financial Analysis Paper  
**Prepared For:** Executive Management & Product Leadership  
**Project:** Betplus / Buzzycash Platform  
**Game Title:** Caged (formerly Bird Escape)  
**Channel:** USSD Shortcode  
**Date:** September 2026  

---

## 1. Executive Summary

Bringing **Caged**—a real-time crash game—to the USSD channel expands the product beyond smartphone owners into the massive offline/feature-phone market in Nigeria. 

Because USSD is a **synchronous, high-latency (2.0s – 3.5s per turn), text-only protocol with strict session timeouts (typically 20–30 seconds maximum total session length before carrier drop)**, players cannot watch an animated canvas or execute split-second manual cash-outs.

To solve this, two distinct game delivery models have been designed:

1. **Option A: Pre-Set Target Multiplier (Auto Cash-Out Crash)**  
   *The digital sportsbook / Aviator-lite model.* Players commit a stake and choose or type a target multiplier (e.g., `1.50x`, `2.00x`, `5.00x`). Their bet is linked to the active continuous crash round. If the bird reaches or exceeds the target before crashing, the bet wins.
   
2. **Option B: "Escape Count" Discrete Prediction (The Nigerian Lottery / Baba Ijebu Model)**  
   *The discrete pick-and-win model.* Players predict specifically how many birds will escape the cage before the door slams shut (Choices: **1, 2, 3, 4, or 5 Birds**). Each choice maps mathematically to fixed payout odds and underlying crash point intervals.

### High-Level Verdict

| Evaluation Metric | Option A: Target Multiplier | Option B: Escape Count (Recommended) |
| :--- | :--- | :--- |
| **Target Audience Alignment** | Tech-savvy crash bettors without web access | Mass-market feature phone & lottery players |
| **Keypad Input Friction** | Moderate-to-High (decimal inputs or multi-step lists) | **Zero (Single digit `1` to `5`)** |
| **House Edge / Profit Margin** | 4.0% – 6.0% (standard crash margin) | **10.0% – 14.0% (optimized USSD margin)** |
| **Telco Fee Resilience** | Fragile on small stakes (< NGN 100) | **Extremely resilient (absorbs aggregator costs)** |
| **Infrastructure Coupling** | High (must synchronize with live Reverb/Redis tick loop) | **Low (decoupled asynchronous resolution)** |
| **Player Churn / Session Drops** | Risk of session timing out during betting window | **Instant bet placement & asynchronous SMS/Balance settlement** |

---

## 2. Deep Dive: Option A — Pre-Set Target Multiplier (Auto Cash-Out)

### 2.1 How the Flow Works
1. Player dials `*920*9#` (or Betplus shortcode) $\rightarrow$ Selects `3. Play Caged`.
2. Screen displays:
   ```text
   Caged Crash
   Bal: NGN 1,500
   1. Quick 1.50x
   2. Double 2.00x
   3. Triple 3.00x
   4. Moonshot 5.00x
   5. Custom Target
   0. Back
   ```
3. Player selects an option (or enters custom multiplier e.g. `2.40`).
4. Player enters stake (e.g., `NGN 200`).
5. Platform reserves stake via `WalletService::reserveStake` and attaches bet to the **next upcoming live round ID**.
6. **Resolution:**
   - If the player is still on the USSD call when the round finishes (~5–10 seconds), the screen displays the outcome.
   - If the round takes longer than telco session limits (30s), the session drops, and the outcome is pushed via SMS / credited to balance silently.

### 2.2 Financial & RTP Profile
- **Default Theoretical RTP:** 95.0% – 96.0%
- **House Edge:** 4.0% – 5.0%
- **Volatility:** Variable (player-determined based on chosen multiplier).
- **Average Ticket Size:** NGN 100 – NGN 500.
- **Profitability Dynamics:**
  - On a NGN 100 stake at 95% RTP, the gross gaming revenue (GGR) per turn is **NGN 5.00**.
  - **The Telco Problem:** If the telco aggregator (Nalo/Hubtel/MTN) charges NGN 3.00 – NGN 6.00 per completed USSD session, a 5% house edge on low stakes leaves **near-zero or negative net margin** after carrier billing fees!

### 2.3 Pros & Cons
* **Pros:**
  - 1-to-1 parity with the web crash experience.
  - Appeals to players already familiar with crash games like Aviator or Sporty Hero.
* **Cons:**
  - **Latency / Timing Disconnect:** In continuous multiplayer crash games, rounds open and close on fixed 5-second betting timers. A USSD user taking 8 seconds to enter their PIN or stake will miss the round start and have to wait for the next cycle, causing carrier timeouts.
  - **Keypad Ergonomics:** Typing decimals like `2.50` on a feature phone keyboard is prone to user error and session drops.

---

## 3. Deep Dive: Option B — "Escape Count" Discrete Prediction (Recommended)

### 3.1 How the Flow Works
Instead of abstract decimal multipliers, the game is framed around an intuitive narrative:
> *"How many birds will escape from the cage before the trap slams shut?"*

1. Player dials shortcode $\rightarrow$ Selects `3. Play Caged`.
2. Screen displays (**Under 120 chars, GSM compliant**):
   ```text
   Caged: Pick Escape Target
   1. 1 Bird (1.30x)
   2. 2 Birds (2.00x)
   3. 3 Birds (3.80x)
   4. 4 Birds (7.50x)
   5. 5 Birds (18.00x)
   0. Back
   ```
3. Player presses **`2`** (2 Birds).
4. Prompt: `Stake amount (Min NGN 50):` $\rightarrow$ Player enters `100`.
5. Confirmation:
   ```text
   Bet Placed!
   Stake: N100 | Target: 2 Birds
   Potential Win: N200
   Round settling in 5s...
   ```
6. If the crash engine generates a flight with $\ge 2$ birds escaped (multiplier $\ge 2.00$), player wins NGN 200 immediately!

### 3.2 Mathematical Calibration & Profitability Model

In this model, the underlying provably fair crash engine multiplier ($M$) maps cleanly into discrete "Escape Milestones":

| Choice | Birds Escaped | Underlying Multiplier Threshold | True Mathematical Probability ($P$) | Proposed Payout Odds | Effective RTP | House Edge (GGR Margin) |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **1** | $\ge 1$ Bird | $\ge 1.35\text{x}$ | 71.8% | **1.25x** | 89.75% | **10.25%** |
| **2** | $\ge 2$ Birds | $\ge 2.10\text{x}$ | 46.2% | **1.90x** | 87.78% | **12.22%** |
| **3** | $\ge 3$ Birds | $\ge 4.20\text{x}$ | 23.1% | **3.80x** | 87.78% | **12.22%** |
| **4** | $\ge 4$ Birds | $\ge 8.50\text{x}$ | 11.4% | **7.50x** | 85.50% | **14.50%** |
| **5** | $\ge 5$ Birds (Jackpot) | $\ge 20.00\text{x}$ | 4.8% | **18.00x** | 86.40% | **13.60%** |
| **Weighted Average** | — | — | — | — | **87.44%** | **12.56%** |

### 3.3 Financial Highlights
- **Blended House Edge:** **12.56%** (compared to ~4.5% on web).
- **Net GGR per NGN 100 Bet:** **NGN 12.56**.
- **Telco Cost Absorption:** Even after deducting NGN 4.00 carrier session costs, Betplus retains **NGN 8.56 pure net profit** per ticket.
- **Micro-Bet Viability:** High margin allows opening minimum stakes down to **NGN 50**, unlocking low-income mass adoption.

### 3.4 Pros & Cons
* **Pros:**
  - **Ultra-Low Friction:** Player only presses a single digit (`1`, `2`, `3`, `4`, `5`). No decimals, no typing errors.
  - **Superior Margins:** 12%–14% GGR margin is healthy enough to support telco aggregator fees, agent commissions, and USSD dialer bonuses.
  - **Local Cultural Fit:** Matches the mental model of Nigerian lottery/Baba Ijebu ("Nap 2", "Direct 1").
  - **Decoupled Asynchronous Execution:** The player does not have to wait on the line for a live round clock; the ticket can settle instantly against an isolated seed or join the queue smoothly.
* **Cons:**
  - Does not look identical to web Aviator-style cashout (though the brand name and universe remain identical).

---

## 4. Side-by-Side Architectural & Operational Comparison

```
+--------------------------+-------------------------------+-------------------------------+
| FACTOR                   | OPTION A: TARGET MULTIPLIER   | OPTION B: ESCAPE COUNT        |
+--------------------------+-------------------------------+-------------------------------+
| User Experience (UX)     | Multi-tier menus or decimals  | 1-key press (1 to 5)          |
| GSM Screen Compliance    | Borderline (risk of overflow) | 100% compliant (< 140 chars)  |
| Round Synchronization    | Coupled to 5s live clock      | Decoupled / Instant Queue     |
| Average Session Duration | 22 - 28 seconds (high risk)   | 10 - 14 seconds (fast & safe) |
| Carrier Drop Rate Risk   | HIGH (~15-20% drop on telco)  | LOW (< 3% drop rate)          |
| House Edge (GGR)         | 4% - 6%                       | 10% - 14%                     |
| Net Margin after Telco   | Thin / Negative on N50 stakes | Highly Profitable at all sizes|
| Customer Support Tickets | High (missed rounds/drops)    | Minimal                       |
| Development Complexity   | Complex (syncing locks/ticks) | Straightforward (FSM state)   |
+--------------------------+-------------------------------+-------------------------------+
```

---

## 5. Risk & Telco Session Latency Analysis

### The 20-Second Telco Guillotine
In Nigeria (MTN, Airtel, Glo, 9mobile), the network terminates USSD sessions after **20 to 30 seconds of inactivity or total session elapsed time**:
1. In **Option A**, if a round just started when the player finishes selecting their multiplier, they must wait up to 15–20 seconds for the current round to crash plus 5 seconds of the betting window. **The telco session will disconnect before the outcome returns.** The player thinks their money is lost until an SMS arrives minutes later, causing high panic and support calls.
2. In **Option B**, the round resolves immediately or queues into the immediate next flight tick with an auto-close payload. The USSD response can be returned in **Turn 2 or Turn 3 (well under 12 seconds total elapsed time)**.

---

## 6. Strategic Recommendation for Management

### Proposed Path: The Hybrid Two-Phase Strategy

1. **Launch Phase 1 with Option B ("Escape Count"):**
   - **Why:** Delivers immediate profitability (12.5% margin), lowest technical risk, zero customer support friction, and full compatibility with cheap feature phones.
   - Fits neatly alongside **BlackRed** and **Heritage** in the existing USSD codebase (`apps/ussd`).
   - Allows marketing to use an exciting street slogan:  
     *“Predict how many birds fly away! Dial `*920*9#` to play Caged!”*

2. **Phase 2 (Future Extension):**
   - If market research reveals a niche of players demanding exact multiplier targets, introduce a submenu under Caged: `1. Escape Count (Instant)` vs `2. Custom Multiplier`.

---

## 7. Next Steps for Implementation

Upon executive sign-off:
1. **Engine Calibration:** Register `CagedUssdEngine` in `apps/platform` using the 12.5% house edge payout table.
2. **USSD FSM Integration:** Add `apps/ussd/states/caged/` state handlers (`EntryState`, `PickBirdsState`, `StakeState`, `ConfirmState`).
3. **SMS Gateway Wiring:** Enable Hubtel/Termii instant win SMS for any dropped sessions so no winning ticket ever goes unacknowledged.
4. **End-to-End Simulation:** Run load & timeout tests against the Nalo USSD simulator.
