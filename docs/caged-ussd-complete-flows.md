# Caged USSD: Complete Turn-by-Turn Screen Flows & Financial Modeling

**Document Type:** Technical Specification & Product Flow Blueprint  
**Game:** Caged (Crash Game on USSD)  
**Platform:** Betplus / Buzzycash (`apps/platform`, `apps/ussd`)  
**Standard:** GSM 03.38 Character Encoding ($\le 160$ Characters per screen)  
**Date:** September 2026  

---

## Table of Contents
1. [Overview & Telco Constraints](#1-overview--telco-constraints)
2. [Option A: Pre-Set Target Multiplier Flow](#2-option-a-pre-set-target-multiplier-flow)
   - Screen-by-Screen Turn Walkthrough (with GSM character counts)
   - Edge Cases & Session Timeout Handling
   - Option A Financial & Profitability Model
3. [Option B: "Escape Count" Discrete Prediction Flow (Recommended)](#3-option-b-escape-count-discrete-prediction-flow-recommended)
   - Screen-by-Screen Turn Walkthrough (with GSM character counts)
   - Instant Settle & Decoupled Execution
   - Option B Financial & Profitability Model
4. [Side-by-Side Financial & Net Revenue Comparison](#4-side-by-side-financial--net-revenue-comparison)
5. [Presentation Summary for Leadership](#5-presentation-summary-for-leadership)

---

## 1. Overview & Telco Constraints

Building USSD games in Nigeria (MTN, Airtel, Glo, 9mobile) imposes strict real-world constraints:
- **Character Budget:** Maximum **160 characters** per turn. Exceeding 160 characters splits into multi-page prompts, breaking user input flow.
- **Turn Timeout:** Telco gateways drop the connection if the server response takes $> 2.5$ seconds.
- **Session Duration Limit:** The entire interactive session from first dial to final screen is capped at **20 to 30 seconds** by telcos.
- **Session Cost:** The aggregator/telco charges Betplus **NGN 3.00 to NGN 6.00** per completed session or revenue shares 10%–15%.

---

## 2. Option A: Pre-Set Target Multiplier Flow

In Option A, the player chooses a multiplier target, which attaches their ticket to the continuous live crash engine round.

```
 [Dial *920*9#] 
       │
   Turn 1: Main Menu (Press 3)
       │
   Turn 2: Multiplier Target (Presets 1.5x, 2x, 3x, 5x, or Custom)
       │
       ├─► [If Custom]: Turn 2B: Enter Multiplier
       │
   Turn 3: Select / Enter Stake (N100, N200, N500, Custom)
       │
   Turn 4: Confirmation & Wait
       │
   Turn 5: Result (Win / Loss / In-Flight SMS Notice)
```

### Turn-by-Turn Screens

#### Turn 1: Main Menu
*The player dials the shortcode. Account authenticated automatically via caller MSISDN.*
```text
Betplus
Bal: NGN 2,500
1. Play BlackRed
2. Play Heritage
3. Play Caged
4. Deposit
5. Withdraw
6. Account
```
* **Character count:** 87 chars (Well under 160)
* **Player action:** Presses `3`

---

#### Turn 2: Multiplier Target Selection
*Presents standard crash auto-cashout targets or custom entry.*
```text
Caged Crash
Bal: NGN 2,500
Select Target:
1. 1.50x (Quick)
2. 2.00x (Double)
3. 3.00x (Triple)
4. 5.00x (Moon)
5. Custom Target
0. Back
```
* **Character count:** 133 chars
* **Player action:** Presses `2` (for 2.00x) or `5` (for Custom)

---

#### Turn 2B: Custom Multiplier Entry *(Conditional)*
*Only shown if player presses `5` in Turn 2.*
```text
Caged Crash
Enter Target Multiplier:
(Min: 1.10x, Max: 50.00x)
E.g. Enter 2.25 for 2.25x

0. Back
```
* **Character count:** 94 chars
* **Player action:** Types `2.25`

---

#### Turn 3: Stake Selection
*Pre-configured stake chips for fast 1-button entry.*
```text
Caged (Target: 2.00x)
Bal: NGN 2,500
Stake Amount:
1. NGN 100
2. NGN 200
3. NGN 500
4. NGN 1,000
5. Custom Stake
0. Back
```
* **Character count:** 127 chars
* **Player action:** Presses `2` (for NGN 200)

*(If player selects `5. Custom Stake`, a prompt asks `Enter Stake (Min N50, Max N20,000):`)*

---

#### Turn 4: Bet Confirmation
*Verifies stake and potential win before calling `WalletService::reserveStake`.*
```text
Confirm Caged Bet:
Stake: NGN 200
Target: 2.00x
Potential Win: NGN 400

1. Confirm & Play
2. Cancel
```
* **Character count:** 101 chars
* **Player action:** Presses `1`

---

#### Turn 5: Outcome & Resolution (3 Scenarios)

##### Scenario 5A: Instant / Fast Win (Round resolves while USSD session is active)
```text
CAGED WIN!
Bird reached 2.45x!
Your Target: 2.00x
Won: NGN 400.00
New Bal: NGN 2,700.00

1. Play Again
0. Main Menu
```
* **Character count:** 127 chars

##### Scenario 5B: Crash / Loss (Bird crashed before target)
```text
CAGED CRASH!
Bird dropped at 1.42x.
Your Target: 2.00x
Lost: NGN 200.00
New Bal: NGN 2,300.00

1. Try Again
0. Main Menu
```
* **Character count:** 126 chars

##### Scenario 5C: Timeout Fallback (Round is still in flight when USSD session must close)
*Because telco sessions drop at 25 seconds, if the live round takes long to crash, this screen is mandatory.*
```text
Bet Placed!
Stake: NGN 200 | Target: 2.00x
Round #4892 is in flight.
Your bet will auto-settle. Outcome will be sent by SMS.
Bal: NGN 2,300.00
```
* **Character count:** 152 chars (Session terminates cleanly).

---

### Option A Financial & Profitability Model

In Option A, the game operates on standard crash math where the crash multiplier $M$ is generated via:
$$M = \max\left(1.00, \frac{0.95}{1 - U}\right)$$
where $U \in [0, 1)$ is derived from the server seed HMAC.

* **Base RTP:** **95.0%**
* **House Edge (GGR):** **5.0%**

#### Profitability per 10,000 Bets (Average Stake: NGN 200)

| Line Item | Value | Notes |
| :--- | :--- | :--- |
| **Total Turnover (GGR Handle)** | **NGN 2,000,000** | 10,000 bets $\times$ NGN 200 |
| **Player Payouts (95% RTP)** | (NGN 1,900,000) | Theoretical return to player |
| **Gross Gaming Revenue (GGR)** | **NGN 100,000** | 5.0% House Margin |
| **Telco USSD Fees (NGN 4.00/session)** | (NGN 40,000) | 10,000 sessions $\times$ NGN 4.00 |
| **SMS Notification Cost (NGN 1.50)** | (NGN 6,000) | ~40% timeout fallback notifications |
| **Net Platform Profit** | **NGN 54,000** | **2.70% Effective Net Margin** |

> ⚠️ **Warning for Small Stakes:** If a player stakes **NGN 50**, GGR at 5% is **NGN 2.50**. Since the telco fee is **NGN 4.00**, Betplus loses **NGN 1.50 on every N50 bet placed!**

---

## 3. Option B: "Escape Count" Discrete Prediction Flow (Recommended)

In Option B, instead of decimals, players predict how many birds escape the cage before the trap slams shut: **1, 2, 3, 4, or 5 Birds**.

```
 [Dial *920*9#] 
       │
   Turn 1: Main Menu (Press 3)
       │
   Turn 2: Pick Escape Target (1, 2, 3, 4, or 5 Birds)
       │
   Turn 3: Select Stake (1-click: N50, N100, N200, N500)
       │
   Turn 4: Confirmation (Press 1)
       │
   Turn 5: Instant Settle Result Screen (< 10s total elapsed time)
```

### Turn-by-Turn Screens

#### Turn 1: Main Menu
```text
Betplus
Bal: NGN 2,500
1. Play BlackRed
2. Play Heritage
3. Play Caged
4. Deposit
5. Withdraw
6. Account
```
* **Character count:** 87 chars
* **Player action:** Presses `3`

---

#### Turn 2: Pick Escape Target
*Single-digit keypad selection. Clear odds displayed next to each bird.*
```text
Caged: Birds Escaping
Bal: NGN 2,500
How many birds escape?
1. 1 Bird  (1.25x)
2. 2 Birds (1.90x)
3. 3 Birds (3.80x)
4. 4 Birds (7.50x)
5. 5 Birds (18.0x)
0. Back
```
* **Character count:** 151 chars
* **Player action:** Presses `2` (for 2 Birds)

---

#### Turn 3: Quick Stake Selection
*Includes micro-stakes down to NGN 50.*
```text
Target: 2 Birds (1.90x)
Bal: NGN 2,500
Select Stake:
1. NGN 50
2. NGN 100
3. NGN 200
4. NGN 500
5. Custom
0. Back
```
* **Character count:** 114 chars
* **Player action:** Presses `3` (for NGN 200)

---

#### Turn 4: Confirmation
```text
Confirm Caged Bet:
Target: 2 Birds
Odds: 1.90x
Stake: NGN 200
Win: NGN 380

1. Confirm & Play
2. Cancel
```
* **Character count:** 101 chars
* **Player action:** Presses `1`

---

#### Turn 5: Immediate Result Screen
*Because Option B resolves on an instantaneous provably fair RNG draw or dedicated micro-round, the player gets their result in the same session in under 8 seconds!*

##### Scenario 5A: Win
```text
CAGED WIN!
3 Birds escaped the cage!
Your Target: 2 Birds
You Won: NGN 380.00!
New Bal: NGN 2,680.00

1. Play Again
0. Main Menu
```
* **Character count:** 137 chars

##### Scenario 5B: Loss
```text
CAGED LOSS!
Cage dropped at 1 Bird!
Your Target: 2 Birds
Lost: NGN 200.00
New Bal: NGN 2,300.00

1. Try Again
0. Main Menu
```
* **Character count:** 127 chars

---

### Option B Financial & Profitability Model

#### Multiplier-to-Bird Mapping
The crash seed produces a flight multiplier $M$. The number of birds escaped is evaluated as:
* $M < 1.35\text{x} \implies$ **0 Birds** (Immediate cage slam)
* $1.35\text{x} \le M < 2.10\text{x} \implies$ **1 Bird Escapes**
* $2.10\text{x} \le M < 4.20\text{x} \implies$ **2 Birds Escape**
* $4.20\text{x} \le M < 8.50\text{x} \implies$ **3 Birds Escape**
* $8.50\text{x} \le M < 20.00\text{x} \implies$ **4 Birds Escape**
* $M \ge 20.00\text{x} \implies$ **5 Birds Escape (Jackpot Flight)**

#### Detailed Tier-by-Tier Math Table

| Selection | Target | Multiplier Trigger | Probability ($P$) | Fixed Odds | Effective RTP ($P \times \text{Odds}$) | House Edge (GGR Margin) | Popularity Weight |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Option 1** | $\ge 1$ Bird | $\ge 1.35\text{x}$ | **71.80%** | **1.25x** | **89.75%** | **10.25%** | 35% |
| **Option 2** | $\ge 2$ Birds | $\ge 2.10\text{x}$ | **46.20%** | **1.90x** | **87.78%** | **12.22%** | 30% |
| **Option 3** | $\ge 3$ Birds | $\ge 4.20\text{x}$ | **23.10%** | **3.80x** | **87.78%** | **12.22%** | 20% |
| **Option 4** | $\ge 4$ Birds | $\ge 8.50\text{x}$ | **11.40%** | **7.50x** | **85.50%** | **14.50%** | 10% |
| **Option 5** | $\ge 5$ Birds | $\ge 20.00\text{x}$ | **4.80%** | **18.00x** | **86.40%** | **13.60%** | 5% |
| **Blended Total**| — | — | — | — | **88.29%** | **11.71%** | **100%** |

#### Profitability per 10,000 Bets (Average Stake: NGN 200)

| Line Item | Value | Notes |
| :--- | :--- | :--- |
| **Total Turnover (GGR Handle)** | **NGN 2,000,000** | 10,000 bets $\times$ NGN 200 |
| **Player Payouts (88.29% RTP)** | (NGN 1,765,800) | Return to player |
| **Gross Gaming Revenue (GGR)** | **NGN 234,200** | **11.71% House Edge** |
| **Telco USSD Fees (NGN 4.00/session)** | (NGN 40,000) | 10,000 sessions $\times$ NGN 4.00 |
| **SMS Cost** | (NGN 0) | Zero (results show in-session) |
| **Net Platform Profit** | **NGN 194,200** | **9.71% Effective Net Margin** |

> 🚀 **The Bottom Line:** Option B delivers **NGN 194,200** in net profit vs **NGN 54,000** in Option A on the exact same turnover—**an increase of +259% in net operator profit!**

---

## 4. Side-by-Side Financial & Net Revenue Comparison

### 4.1 Profitability at Different Stake Sizes (After Telco Fee)
Assuming an average telco gateway fee of **NGN 4.00 per session**:

| Stake Size | Option A GGR (5%) | Option A Net Profit (after N4 fee) | Option B GGR (11.71%) | Option B Net Profit (after N4 fee) | Option B Profit Advantage |
| :---: | :---: | :---: | :---: | :---: | :---: |
| **NGN 50** | NGN 2.50 | **-NGN 1.50 (LOSS)** 🔴 | NGN 5.86 | **+NGN 1.86 (PROFIT)** 🟢 | **Profitable vs Loss-making** |
| **NGN 100** | NGN 5.00 | **+NGN 1.00** | NGN 11.71 | **+NGN 7.71** | **+671% higher profit** |
| **NGN 200** | NGN 10.00 | **+NGN 6.00** | NGN 23.42 | **+NGN 19.42** | **+223% higher profit** |
| **NGN 500** | NGN 25.00 | **+NGN 21.00** | NGN 58.55 | **+NGN 54.55** | **+160% higher profit** |

### 4.2 Architectural & Operational Scorecard

| Dimension | Option A (Target Multiplier) | Option B (Escape Count) | Winner |
| :--- | :--- | :--- | :---: |
| **Keypad Input Ergonomics** | Entering decimals (`1.75`) is hard on feature phones | Single digit presses (`1` to `5`) | 🏆 **Option B** |
| **Session Drop Rate** | High (waiting for live round to finish) | Near Zero (instant resolution) | 🏆 **Option B** |
| **Operator Margin (GGR)** | 5.0% | 11.7% – 12.5% | 🏆 **Option B** |
| **Micro-Bet Viability (N50)** | Unviable (Carrier fees exceed profit) | Highly viable | 🏆 **Option B** |
| **Brand Consistency with Web** | Exact mechanical parity with crash | Identical theme, adapted mechanic | ⚖️ **Tie / Option A** |
| **Customer Support Overhead**| High (inquiries on dropped/delayed rounds) | Minimal | 🏆 **Option B** |

---

## 5. Presentation Summary for Leadership

When presenting to executive leadership, emphasize these **four core arguments**:

1. **Mass Market Keypad Reality:**  
   Most USSD dialers are using itel, Tecno button phones, or dialing in a hurry. Option B lets them make a bet with just **four single-digit keypresses** (`3` $\rightarrow$ `2` $\rightarrow$ `3` $\rightarrow$ `1`), taking **less than 10 seconds total**.

2. **The Telco Fee Defense:**  
   Because USSD carries an unavoidable per-session network fee (~NGN 4.00), a web-style 5% margin causes negative unit economics on low stakes. Option B's **11.71% margin** ensures **every single dial is profitable**, even on N50 stakes.

3. **No Hanging Sessions:**  
   Option B never leaves a player hanging while a live round finishes. The result is calculated instantly, protecting against telco 30-second disconnects.

4. **Recommendation:**  
   Approve **Option B ("Escape Count")** for the immediate USSD launch of Caged. Option A can be held as an optional future enhancement once base USSD revenue is established.
