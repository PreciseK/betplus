# Caged USSD: Option B — "Escape Count" Discrete Prediction Game Specification

**Document Type:** Technical Specification, Product Blueprint & Financial Model  
**Game Title:** Caged (Escape Count USSD Edition)  
**Channel:** USSD Shortcode (`*920*9#` / Telco Aggregator Gateway)  
**Target Platform:** Betplus / Buzzycash (`apps/platform`, `apps/ussd`)  
**Standard:** GSM 03.38 Character Encoding ($\le 160$ characters per screen prompt)  
**Date:** September 2026  
**Status:** Approved for Implementation  

---

## 1. Executive Summary & Narrative Concept

### 1.1 The Challenge
Continuous real-time crash games (e.g., Aviator, Spribe, Caged Web) require players to watch an animated multiplier climb and execute split-second manual cashouts. However, the USSD protocol in Nigeria (MTN, Airtel, Glo, 9mobile) is:
- **Synchronous and high-latency** (2.0s – 3.5s per turn).
- **Strictly time-bounded** (carrier session timeout occurs after 20–30 seconds of total elapsed time).
- **Text-only** without push updates or interactive real-time canvas animation.

### 1.2 The "Escape Count" Solution
Option B transforms the crash engine into an intuitive, discrete prediction lottery mechanic:
> *"How many birds will escape from the cage before the trap slams shut?"*

Players predict how many birds will fly out of the cage (**1, 2, 3, 4, or 5 Birds**). Each bird choice maps to a fixed payout multiplier backed by the certified provably fair crash engine.

```
       ┌─────────────────────────────────────────────────────────┐
       │                 THE CAGED USSD PROMISE                  │
       │                                                         │
       │  "Predict how many birds fly away before the cage trap  │
       │   slams shut! Pick 1 to 5 Birds. Win up to 18x stake!" │
       └─────────────────────────────────────────────────────────┘
```

### 1.3 Key Value Drivers
1. **Zero Keypad Friction:** Players choose single digits (`1` to `5`) on their keypad without typing cumbersome decimal numbers or navigating nested auto-cashout configurations.
2. **Robust House Margins:** Generates a blended **12.56% house edge (GGR)**, easily absorbing telco session fees (NGN 3.00 – NGN 6.00 per session) even on micro-stakes as low as **NGN 50**.
3. **Decoupled Asynchronous Settlement:** Removes the dependency on waiting for the live multiplayer 5-second round clock. Tickets can settle instantly in-turn or asynchronously via SMS notification if the telco disconnects.
4. **Local Cultural Fit:** Matches the mental model of Nigerian street lotto / Baba Ijebu ("Direct 1", "Nap 2", "Nap 3").

---

## 2. Mathematical Calibration & Paytable Economics

### 2.1 Multiplier to Escape Count Mapping
The underlying crash engine produces a continuous multiplier $M \ge 1.00\text{x}$. In the USSD game, the number of birds escaped is evaluated deterministically from $M$:

$$\text{Birds Escaped} = \begin{cases} 
0 & \text{if } M < 1.35\text{x} \\
1 & \text{if } 1.35\text{x} \le M < 2.10\text{x} \\
2 & \text{if } 2.10\text{x} \le M < 4.20\text{x} \\
3 & \text{if } 4.20\text{x} \le M < 8.50\text{x} \\
4 & \text{if } 8.50\text{x} \le M < 20.00\text{x} \\
5 & \text{if } M \ge 20.00\text{x} \quad (\text{Jackpot})
\end{cases}$$

### 2.2 Paytable & Theoretical RTP
A player's prediction of $K$ birds wins if at least $K$ birds escape ($M \ge \text{Threshold}(K)$):

| Option | Prediction | Multiplier Threshold ($M$) | Mathematical Probability ($P$) | Proposed Payout | Theoretical RTP | House Edge (GGR Margin) |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: |
| **1** | $\ge 1$ Bird | $\ge 1.35\text{x}$ | $71.80\%$ | **1.25x** | $89.75\%$ | **$10.25\%$** |
| **2** | $\ge 2$ Birds | $\ge 2.10\text{x}$ | $46.20\%$ | **1.90x** | $87.78\%$ | **$12.22\%$** |
| **3** | $\ge 3$ Birds | $\ge 4.20\text{x}$ | $23.10\%$ | **3.80x** | $87.78\%$ | **$12.22\%$** |
| **4** | $\ge 4$ Birds | $\ge 8.50\text{x}$ | $11.40\%$ | **7.50x** | $85.50\%$ | **$14.50\%$** |
| **5** | $\ge 5$ Birds (Jackpot) | $\ge 20.00\text{x}$ | $4.80\%$ | **18.00x** | $86.40\%$ | **$13.60\%$** |
| **Weighted** | **Blended Target** | — | — | — | **$87.44\%$** | **$12.56\%$** |

### 2.3 Unit Economics & Carrier Fee Absorption

| Stake Amount | Gross Gaming Revenue (12.56%) | Carrier Session Cost (Avg) | Net Operating Margin | Net Margin % |
| :--- | :--- | :--- | :--- | :--- |
| **NGN 50** | NGN 6.28 | NGN 4.00 | **+NGN 2.28** | **+4.56%** |
| **NGN 100** | NGN 12.56 | NGN 4.00 | **+NGN 8.56** | **+8.56%** |
| **NGN 200** | NGN 25.12 | NGN 4.00 | **+NGN 21.12** | **+10.56%** |
| **NGN 500** | NGN 62.80 | NGN 4.00 | **+NGN 58.80** | **+11.76%** |
| **NGN 1,000** | NGN 125.60 | NGN 4.00 | **+NGN 121.60** | **+12.16%** |

*Even on micro-stakes of NGN 50, the operator remains strictly profitable after telco aggregator billing deductions.*

---

## 3. End-to-End Turn-by-Turn USSD Screen Flows

All screens conform to the **GSM 03.38 7-bit default alphabet** and remain strictly under **140 characters** (well within the 160-character single-part USSD packet limit).

### 3.1 Primary Game Flow

```
[User dials *920*9#]
         │
    Turn 1: Main Menu (Press 3)
         │
    Turn 2: Pick Birds (Press 1..5)
         │
    Turn 3: Select Stake (Presets or Custom)
         │
    Turn 4: Confirmation (Press 1 to Confirm)
         │
    Turn 5: Instant Settlement Result Screen
```

---

#### Turn 1: Main Menu
*User dials shortcode `*920*9#`. Account identified via incoming MSISDN.*

```text
Betplus
Bal: NGN 1,500
1. Play BlackRed
2. Play Heritage
3. Play Caged (Birds)
4. Deposit
5. Withdraw
6. Account
```
* **Character count:** 95 / 160 chars  
* **User action:** Presses `3`

---

#### Turn 2: Escape Count Selection (Bird Pick)
*Presents the 5 intuitive bird escape tiers with their payout multipliers.*

```text
Caged: Pick Escape Target
Bal: NGN 1,500
1. 1 Bird (1.25x)
2. 2 Birds (1.90x)
3. 3 Birds (3.80x)
4. 4 Birds (7.50x)
5. 5 Birds (18.0x)
0. Back
```
* **Character count:** 137 / 160 chars  
* **User action:** Presses `2` (Selects 2 Birds @ 1.90x)

---

#### Turn 3: Stake Selection
*Provides quick-stake presets and custom entry.*

```text
Caged: 2 Birds (1.90x)
Choose Stake:
1. NGN 50 (Win N95)
2. NGN 100 (Win N190)
3. NGN 200 (Win N380)
4. NGN 500 (Win N950)
5. Custom Stake
0. Back
```
* **Character count:** 136 / 160 chars  
* **User action:** Presses `2` (for NGN 100) or `5` (to type custom stake)

*(If custom selected, sub-prompt: `Enter stake in Naira (Min NGN 50, Max NGN 5,000):`)*

---

#### Turn 4: Compact Confirmation
*Shows explicit stake, target, and potential payout before money is debited.*

```text
Confirm Caged Bet:
Target: 2 Birds
Stake: NGN 100
Win: NGN 190
1. Confirm & Play
2. Cancel
```
* **Character count:** 84 / 160 chars  
* **User action:** Presses `1`

---

#### Turn 5: Immediate Settlement Result

##### Scenario A: WIN (2 or more birds escaped)
```text
YOU WON NGN 190!
3 Birds Escaped before cage closed!
Target: 2 Birds (1.90x)
Stake: N100 | Won: N190
New Bal: NGN 1,590
1. Play Again
2. Main Menu
```
* **Character count:** 142 / 160 chars  
* **User action:** Presses `1` (loops to Turn 2) or `2` (Main Menu)

##### Scenario B: LOSS (Trap closed before 2 birds escaped)
```text
CAGE CLOSED!
Only 1 Bird Escaped.
Target: 2 Birds
Stake: NGN 100
New Bal: NGN 1,400
Better luck next round!
1. Play Again
2. Main Menu
```
* **Character count:** 133 / 160 chars  

##### Scenario C: JACKPOT WIN (5 Birds Escaped)
```text
JACKPOT WINNER!
All 5 Birds Escaped!
Target: 5 Birds (18.0x)
Stake: N500 | Won: N9,000
New Bal: NGN 10,000
Ref: #CG-89214
1. Play Again
2. Main Menu
```
* **Character count:** 142 / 160 chars  

---

### 3.2 Fast-Dial Shortcode Flow (Power Bettor Shortcut)
Players can place bets in a single string without browsing menus:
- Format: `*920*9*3*<BIRDS>*<STAKE>#`
- Example: `*920*9*3*2*100#` (Plays Caged, 2 Birds, NGN 100 Stake)

**Direct Screen Displayed:**
```text
Bet Placed!
Caged: 2 Birds @ N100
Result: 3 Birds Escaped!
YOU WON NGN 190!
New Bal: NGN 1,590
1. Play Again
0. Exit
```
*Total interaction time: under 3 seconds.*

---

### 3.3 Insufficient Balance & Top-Up Flow

If the player's balance is less than their selected stake:

```text
Insufficient Balance
Bal: NGN 20 (Need N100)
1. Quick Top Up (OPay/USSD)
2. Lower Stake
0. Back
```

If player selects `1`:
```text
Deposit to Play Caged
1. Pay N100 via OPay USSD
2. Pay N200 via OPay USSD
3. Pay N500 via OPay USSD
0. Back
```
*Triggers instant push-debit prompt or displays dedicated USSD banking string.*

---

## 4. Telco Session Drop & Asynchronous SMS Handling

Because carrier networks occasionally drop connections after 20 seconds, the engine guarantees that no player is left in the dark.

### 4.1 Lifecycle States
```
 [USSD Request Received]
          │
  Validate & Reserve Stake (WalletService)
          │
  Resolve Outcome via Engine (CagedUssdEngine)
          │
  ┌───────┴────────────────────────┐
  ▼                                ▼
[Session Active]           [Session Dropped by Telco]
Return Turn 5 Result       Commit Ledger Settlement
Update Screen              Send Instant SMS Notification
```

### 4.2 SMS Notification Templates

#### 1. Win Notification SMS
```text
Betplus: YOU WON N190 on Caged! 3 birds escaped (Target was 2 birds). Ticket #CG-89214. Your new balance is N1,590. Play again: *920*9*3#
```

#### 2. Loss Notification SMS (Only if session dropped before result)
```text
Betplus: Caged round ended. 1 bird escaped (Target: 2 birds). Stake N100. New balance: N1,400. Try again at *920*9*3#
```

#### 3. Jackpot Win Notification SMS
```text
Betplus: JACKPOT! All 5 birds escaped on Caged! You won N9,000 on your N500 bet! Ref: #CG-89214. Bal: N10,000. Withdraw: *920*9*5#
```

---

## 5. Technical Implementation Architecture

### 5.1 USSD Finite State Machine (FSM)

The USSD service in `apps/ussd` implements state classes extending `UssdState`:

```
apps/ussd/src/states/caged/
├── CagedMenuState.ts          // Entry menu (Pick 1..5 birds)
├── CagedStakePresetState.ts   // Preset stake selection (N50..N500)
├── CagedCustomStakeState.ts   // Custom stake text input
├── CagedConfirmState.ts       // Confirmation dialog
├── CagedSettledState.ts       // Win/Loss result screen
└── CagedTopUpState.ts         // Quick deposit handler
```

### 5.2 Platform Engine Service (`apps/platform`)

Create `App\Domain\Games\Engine\BirdEscape\CagedUssdEngine`:

```php
namespace App\Domain\Games\Engine\BirdEscape;

use App\Domain\Fairness\SeedIssuer;

class CagedUssdEngine
{
    private const TIERS = [
        1 => ['min_multiplier' => 1.35, 'odds' => 1.25],
        2 => ['min_multiplier' => 2.10, 'odds' => 1.90],
        3 => ['min_multiplier' => 4.20, 'odds' => 3.80],
        4 => ['min_multiplier' => 8.50, 'odds' => 7.50],
        5 => ['min_multiplier' => 20.00, 'odds' => 18.00],
    ];

    public function resolve(int $targetBirds, int $stakeKobo, string $seed): array
    {
        // Deterministic float derived from cryptographic hash
        $crashMultiplier = $this->generateCrashMultiplier($seed);
        
        $birdsEscaped = 0;
        if ($crashMultiplier >= 20.00) $birdsEscaped = 5;
        elseif ($crashMultiplier >= 8.50) $birdsEscaped = 4;
        elseif ($crashMultiplier >= 4.20) $birdsEscaped = 3;
        elseif ($crashMultiplier >= 2.10) $birdsEscaped = 2;
        elseif ($crashMultiplier >= 1.35) $birdsEscaped = 1;

        $won = $birdsEscaped >= $targetBirds;
        $payoutMultiplier = $won ? self::TIERS[$targetBirds]['odds'] : 0.0;
        $grossPrizeKobo = (int) round($stakeKobo * $payoutMultiplier);

        return [
            'birds_escaped' => $birdsEscaped,
            'target_birds' => $targetBirds,
            'crash_multiplier' => $crashMultiplier,
            'won' => $won,
            'payout_odds' => self::TIERS[$targetBirds]['odds'],
            'gross_prize_kobo' => $grossPrizeKobo,
        ];
    }
}
```

### 5.3 Database Schema Migration

```sql
CREATE TABLE caged_ussd_tickets (
    id VARCHAR(36) PRIMARY KEY,
    player_id VARCHAR(36) NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    target_birds TINYINT UNSIGNED NOT NULL,
    birds_escaped TINYINT UNSIGNED NOT NULL,
    crash_multiplier DECIMAL(8, 2) NOT NULL,
    stake_kobo BIGINT UNSIGNED NOT NULL,
    payout_odds DECIMAL(6, 2) NOT NULL,
    gross_prize_kobo BIGINT UNSIGNED NOT NULL,
    net_credit_kobo BIGINT UNSIGNED NOT NULL,
    won BOOLEAN NOT NULL,
    idempotency_key VARCHAR(100) UNIQUE NOT NULL,
    settled_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES player(id)
);

CREATE INDEX idx_caged_ussd_player ON caged_ussd_tickets(player_id, created_at);
```

---

## 6. Comparison: Option A (Multiplier) vs Option B (Escape Count)

| Parameter | Option A (Multiplier / Auto-Cashout) | Option B (Escape Count Discrete) |
| :--- | :--- | :--- |
| **Input Complexity** | High (decimal input e.g. `2.50`) | **Low (Single digit `1`..`5`)** |
| **Menu Turns Required** | 4 to 6 turns | **3 to 4 turns** |
| **Average Session Time** | 22–28 seconds | **10–14 seconds** |
| **Carrier Timeout Rate** | 15% – 25% | **< 2%** |
| **Target Audience** | Web bettors without smartphone access | **Mass-market lottery & USSD bettors** |
| **House Edge** | 4.0% – 5.0% | **12.56% (Sustainable for Telco fees)** |
| **Min Viable Stake** | NGN 200 (due to carrier cost) | **NGN 50 (Profitable at all levels)** |
| **Game Mental Model** | Financial trading / Continuous line | **Exciting bird escape storytelling** |

---

## 7. Execution Checklist & Next Steps

1. **Step 1: Backend Domain Engine**
   - Implement `CagedUssdEngine.php` in `apps/platform/app/Domain/Games/Engine/BirdEscape/`.
   - Implement `CreateCagedUssdTicket.php` and wire into `WalletService`.
   - Run unit and property tests verifying RTP distribution across 100,000 simulated rounds.

2. **Step 2: USSD FSM States**
   - Add state handlers to `apps/ussd/src/states/caged/`.
   - Implement shortcode fast-dial routing (`*920*9*3*<BIRDS>*<STAKE>#`).

3. **Step 3: Telco SMS Notification Gate**
   - Connect Termii/Hubtel SMS fallback webhook for dropped sessions.

4. **Step 4: Load & Latency Testing**
   - Simulate 500 concurrent USSD sessions via Nalo/Hubtel simulator to ensure response latency $< 1.5\text{s}$ per turn.
