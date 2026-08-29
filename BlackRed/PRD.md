# Product Requirement Document (PRD): BlackRed Raffle Platform

**Project Name:** BlackRed Raffle Platform (Buzzycash)  
**Document Version:** 2.1  
**Status:** Approved / Active Production  
**Target Market:** Ghana (Currency: GHS / Pesewas)  
**Primary Channels:** Web Application, Mobile Web, and USSD (`*920*9#`)  

---

## 1. Executive Summary & Vision

### 1.1 Overview
The **BlackRed Raffle Platform** (Buzzycash) is an instant-draw card-prediction gaming and fintech ecosystem operating in Ghana. It offers players a secure, transparent, and accessible platform to place stakes on card color predictions (Black vs. Red) across 1 to 5 card sequence draws with multipliers ranging from 2x up to 100x.

The system is built on a high-integrity, double-entry financial ledger with dual-balance player wallets (Play Balance vs. Payout Balance) designed to strictly enforce Anti-Money Laundering (AML) policies, state gambling compliance, and seamless Mobile Money (MoMo) settlement across MTN, Telecel (Vodafone), and AirtelTigo.

### 1.2 Core Objectives
- **Omnichannel Accessibility:** Deliver instant gameplay across responsive Web/App interfaces and USSD shortcode (`*920*9#` via Nalo Solutions) for non-smartphone users.
- **Financial Integrity & Double-Entry Accounting:** Store all monetary figures in integer pesewas (`BIGINT`) with double-entry ledger enforcement (`debit` sum = `credit` sum) to eliminate financial discrepancies.
- **AML & Regulatory Compliance:** Enforce dual-balance separation (Play vs. Payout) and automated 50% wagering constraints on Play Balance withdrawals.
- **Fair & Verifiable Gameplay:** Provide cryptographically seedable RNG draw results with full audit trails.

---

## 2. User Personas & Target Audiences

| Persona | Channel | Key Behaviors & Goals |
| :--- | :--- | :--- |
| **Mobile Web Player** | Web / Mobile Web | Plays interactive card selection games, deposits via MoMo, tracks game history visually, and manages payouts. |
| **USSD Player** | USSD (`*920*9#`) | Dial-in player using feature phones or low-bandwidth connections. Quick wagering, quick balance check, simple deposit/withdrawal flows. |
| **Compliance & AML Officer** | Admin Dashboard | Monitors suspicious activities, verifies player KYC records, reviews flagged withdrawals, and inspects audit logs. |
| **Finance Operator** | Admin Dashboard | Performs daily MoMo float reconciliations, resolves stuck/in-doubt transactions, manages system liquidity, and views revenue reports. |
| **Draw & Game Operator** | System / Admin | Manages active game events, monitors RNG integrity, and oversees marketing event schedules. |

---

## 3. System Architecture & Tech Stack

```
               ┌────────────────────────────────────────────────────────┐
               │                     CLIENT CHANNELS                    │
               │   Web App  │  Mobile App  │  USSD Shortcode (*920*9#)  │
               └───────────────────┬────────────────────────────────────┘
                                   │
                                   ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                         BLACKRED CORE ENGINE & SERVICES                      │
│                                                                              │
│  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────────────────┐  │
│  │   HTTP Router    │  │  Auth & Session  │  │  USSD FSM State Engine     │  │
│  │  & Middleware    │  │     Manager      │  │    (Nalo Webhook Receiver) │  │
│  └────────┬─────────┘  └────────┬─────────┘  └─────────────┬──────────────┘  │
│           │                     │                        │                   │
│           ▼                     ▼                        ▼                   │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │                           BUSINESS SERVICES                            │  │
│  │                                                                        │  │
│  │  ┌───────────────────────┐ ┌───────────────────────┐ ┌──────────────┐  │  │
│  │  │ GameEngineService     │ │ Wallet & Ledger       │ │ Deposit &    │  │  │
│  │  │ (RNG & Instant Draw)  │ │ Service (Pesewas)     │ │ Withdrawal   │  │  │
│  │  └───────────────────────┘ └───────────────────────┘ └──────────────┘  │  │
│  └──────────────────────────────────┬─────────────────────────────────────┘  │
└─────────────────────────────────────┼────────────────────────────────────────┘
                                      │
       ┌──────────────────────────────┼──────────────────────────────┐
       ▼                              ▼                              ▼
┌──────────────┐             ┌──────────────────┐           ┌──────────────────┐
│  MARIADB /   │             │   ANM MOMO API   │           │   HUBTEL SMS     │
│   MYSQL 8    │             │ (MTN, TEL, ATL)  │           │ (OTPs & Receipts)│
└──────────────┘             └──────────────────┘           └──────────────────┘
```

### 3.1 Tech Stack Specification
- **Language & Runtime:** PHP 8.1+ (PSR-4 autoloading via Composer).
- **Architecture Pattern:** Custom lightweight PSR-4 modular architecture (Router, Middleware, Controllers, Services, PDO Database Wrapper).
- **Database:** MariaDB 10.5+ / MySQL 8.0+ (`utf8mb4`).
- **External Integrations:**
  - **ANM Mobile Money API:** Mobile Money deposit, withdrawal, and name lookup across MTN, Telecel (VOD), AirtelTigo (ATL).
  - **Nalo Solutions USSD API:** Shortcode webhook integration for `*920*9#`.
  - **Hubtel SMS API:** Transactional SMS receipts and authentication OTP delivery.
- **Logging & Diagnostics:** Monolog for structured logging, plus custom file logging for USSD inbound/outbound turns.

---

## 4. Functional Requirements & Feature Modules

### 4.1 Identity, KYC & Authentication
1. **MSISDN Primary Identity:**
   - Primary player identifier is their E.164-compatible mobile number (e.g. `0244000000`). Unique constraint enforced per player.
   - Normalized automatically across channels (converts `233xxxxxxxxx` to `0xxxxxxxxx`).
2. **Multi-Channel Registration:**
   - Supports self-registration via Web, App, or USSD.
   - Web/App requires password and optional 4-digit transaction PIN.
   - USSD-only players can register seamlessly via automated MoMo name lookup.
3. **KYC Verification Tiers & Name Matching:**
   - Automated check against telco name lookup (`momoNameMatch`: `exact`, `partial`, `mismatch`).
   - Supports Ghana Card, Passport, Voter ID, and Driver's License tracking for elevated KYC verification.
4. **Compliance Retention:**
   - Soft deletes (`deletedAt`) retain player records for 7 years per statutory AML regulations.

### 4.2 Financial Ledger & Dual-Balance Wallet System
1. **Double-Entry Accounting Architecture:**
   - Every monetary transaction creates equal and balancing `debit` and `credit` `ledgerEntry` records.
   - Truth lives strictly in `ledgerEntry`. Wallet balances are transactionally cached with optimistic concurrency control (`version` column).
2. **Denomination:**
   - Stored strictly in **Pesewas** (`BIGINT`). GHS 1.00 = 100 pesewas. Floating-point or decimal arithmetic is forbidden.
3. **Dual-Balance Architecture:**
   - **Play Balance (`PLAYER_PLAY`):** Holds deposited funds and internal transfers. Dedicated to placing game stakes.
   - **Payout Balance (`PLAYER_PAYOUT`):** Holds game winnings. Fully withdrawable without restriction.
4. **Internal Transfer:**
   - Players can convert Payout Balance to Play Balance freely at any time (`INTERNAL_XFER`).
5. **Withdrawal AML Rules:**
   - **Payout Balance Withdrawal:** Unrestricted withdrawal directly to player's MoMo account.
   - **Play Balance Withdrawal (50% Wagering Rule):** Withdrawals from Play Balance are evaluated against a wagering constraint: player must have wagered at least 50% of deposited funds prior to requesting a direct Play Balance cashout.

### 4.3 Game Engine & Instant-Draw Mechanism
1. **Game Concept (Black vs. Red):**
   - Players select a sequence of 1 to 5 card colors (e.g., `B`, `BR`, `BRRBB`).
2. **Multiplier & Payout Structure:**
   - **1 Card Selection:** 2x Payout Multiplier.
   - **2 Card Selection:** 10x Payout Multiplier.
   - **3 Card Selection:** 20x Payout Multiplier.
   - **4 Card Selection:** 50x Payout Multiplier.
   - **5 Card Selection:** 100x Payout Multiplier.
3. **Execution Lifecycle:**
   - **Stake Placement:** Stake debited from Play Balance into system `SUSPENSE` account via `walletTransaction` (`STAKE`).
   - **Instant RNG Draw:** Machine generates draw result sequence (`drawResult`).
   - **Resolution:**
     - **Win:** Payout credited to Payout Balance from `SUSPENSE` (`WIN_PAYOUT`).
     - **Loss:** Stake transferred from `SUSPENSE` to `HOUSE_REVENUE` (`LOSS_FORFEIT`).
4. **RNG Auditability:**
   - Every draw records `rngSeed`, `rngAlgorithm`, and `rngOutput` in `drawResult` for independent verification.

### 4.4 USSD Interface Channel (`*920*9#`)
1. **State Machine Dispatcher:**
   - DB-backed USSD session state handler (`ussdSession`).
   - Concurrency locking via `SELECT ... FOR UPDATE` per turn.
2. **USSD Menu Flow:**
   - **Unregistered Menu:** 1. Register, 2. How to Play, 3. Terms.
   - **Registered Main Menu:** 1. Play BlackRed, 2. Check Balances, 3. Deposit, 4. Withdraw, 5. Last Game Details.
3. **Session Lifecycle & Cleanup:**
   - Automatic 7-minute expiration window for inactive USSD turns.
   - Background cleanup worker running via cron every 5 minutes.

### 4.5 External Integrations & Gateways
1. **ANM Mobile Money Gateway:**
   - Asynchronous deposit collection (STK Push / MoMo prompt).
   - Direct MoMo payout disbursement.
   - Name lookup API integration for KYC verification during registration.
2. **Hubtel SMS Gateway:**
   - Transactional alerts for deposits, withdrawals, and game wins.
   - OTP delivery for authentication and password resets.
3. **Nalo Solutions Webhook Receiver:**
   - Inbound webhook endpoint processing Telco JSON payloads (`SESSIONID`, `MSISDN`, `USERDATA`, `NETWORK`).

---

## 5. Database Schema Structure Overview

The database (`amoamvfc_blackRedTSQL`) comprises 5 main relational layers:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 1. IDENTITY LAYER                                                           │
│    - player (id, msisdn, registeredName, passwordHash, kycStatus, channel)  │
│    - kycRecord (playerId, idType, idNumber, momoNameMatch, verification)    │
│    - institutionUser & institutionRole (Staff RBAC: SUPER_ADMIN, COMPLIANCE) │
│    - playerSession & passwordResetRequest                                   │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 2. WALLET & LEDGER LAYER                                                    │
│    - account (accountCode, accountType: PLAYER_PLAY/PAYOUT, HOUSE_REVENUE)  │
│    - wallet (playerId, walletType, accountId, cachedBalancePesewas, version)│
│    - walletTransaction (refNumber, txnType, amountPesewas, status)           │
│    - ledgerEntry (walletTxnId, accountId, side: debit|credit, amountPesewas)│
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 3. TRANSACTIONS & LIFECYCLE LAYER                                           │
│    - depositRequest (refNumber, playerId, msisdn, amountPesewas, status)    │
│    - withdrawalRequest (refNumber, sourceWallet, fiftyPercentRuleApplied)   │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 4. GAME & DRAW LAYER                                                        │
│    - gameEvent (eventNumber, cardCount, multiplier, playerSelection)        │
│    - gamePlay (refNumber, stakePesewas, potentialPayout, winStatus)          │
│    - drawResult (eventId, drawSelection, rngSeed, rngOutput)                │
│    - gamePayout (gamePlayId, payoutPesewas, walletTxnId)                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 5. INTEGRATION & AUDIT LAYER                                                │
│    - momoApiCallLog (callType, provider, endpoint, requestRef, outcome)     │
│    - smsLog (recipientMsisdn, messageBody, providerRef, deliveryStatus)     │
│    - suspiciousActivityFlag (playerId, flagReason, severity)                │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 6. Non-Functional Requirements (NFRs)

### 6.1 Security & Compliance
- **Data Protection:** All sensitive tokens (passwords, PINs, password reset tokens) are hashed using secure modern algorithms (`password_hash` with BCrypt / SHA-256).
- **Idempotency:** Every financial operation requires a globally unique `refNumber` key to prevent duplicate processing.
- **Audit Logging:** Every external API request/response (ANM, Hubtel) and internal ledger posting is saved immutably.

### 6.2 Financial Accuracy & Concurrency
- **Double-Entry Balance Enforcement:** Sum of debits minus sum of credits across `ledgerEntry` for any `walletTransaction` must equal 0. Any non-zero imbalance is a P0 incident.
- **Optimistic Locking:** Wallet updates check and increment the `version` counter to prevent race conditions during simultaneous gameplay and withdrawal requests.
- **Nightly Reconciliation:** Cron job verifies `cachedBalancePesewas` against the calculated sum of `ledgerEntry` records for every active wallet.

### 6.3 Performance & Availability
- **USSD Response Time:** USSD turns must process and respond within **2.5 seconds** to prevent telco gateway timeouts.
- **Database Transaction Overhead:** In-flight transactions held for minimum time using narrow `FOR UPDATE` scopes.
- **Session Cleanup:** Cron purges expired USSD sessions every 5 minutes.

---

## 7. Operational Workflow & Edge Cases

### 7.1 Deposit Lifecycle & Callback Handling
1. Player initiates deposit (Web or USSD).
2. `depositRequest` is created with status `initiated`.
3. ANM MoMo API `deposit` endpoint is invoked.
4. Telco returns `pending` with `momoTxnId`.
5. Upon user approving USSD MoMo prompt, ANM posts callback to `/callbacks/momo.php`.
6. Callback handler verifies signature, updates `depositRequest` status to `succeeded`, and triggers `walletTransaction` (`DEPOSIT`) crediting Play Balance double-entry ledger.

### 7.2 In-Doubt Withdrawal & Reversal Handling
1. If an ANM withdrawal call times out or remains pending, the `withdrawalRequest` status is set to `reconciling`.
2. Funds remain in `SUSPENSE` account until async status check or manual operator reconciliation confirms success or failure.
3. If declared failed, a `REVERSAL` `walletTransaction` credits the player's wallet back automatically.

---

## 8. Release Strategy & PR Phase Mapping

| PR Phase | Description & Features Covered | Status |
| :--- | :--- | :--- |
| **PR 1** | Foundation: Database Schema 2.1, Core Accounting Engine, Basic USSD Webhook Handler & Session DB. | Completed |
| **PR 2** | USSD EntryState, Registered vs. Unregistered Routing, Balance & Last Stake Read Menu. | Active / In Progress |
| **PR 3** | USSD Registration Sub-flow with ANM Name Lookup integration. | Next |
| **PR 4** | USSD Play Sub-flow (Card selection, stake placement, GameEngineService integration). | Planned |
| **PR 5** | USSD Deposit Sub-flow (Async MoMo trigger + Hubtel SMS notification). | Planned |
| **PR 6** | USSD Withdrawal Sub-flow (50% AML Wagering Rule check + MoMo transfer). | Planned |
| **PR 7** | System Hardening, Observability Metrics, Monolog Audit Integration & Load Testing. | Planned |
