# Betplus

> **Multi-Game Instant-Win Gaming Platform for Nigeria**  
> Live Domain: [https://betplus.com.ng](https://betplus.com.ng)

---

## 1. Overview

**Betplus** is a high-integrity, multi-game instant-win gaming platform engineered specifically for the Nigerian market. Players hold a single identity, a unified wallet, and a single compliance perimeter across every game and channel.

* **Market:** Federal Republic of Nigeria
* **Payment Rail:** OPay (Exclusive direct-to-wallet integration)
* **Channels:** Responsive Web, React Native Mobile App (Android & iOS), and USSD
* **Regulatory Compliance:** Built strictly under `BP-AML-001` with automated 1× turnover controls, state-partitioned withholding tax remittance, and real-time Responsible Gaming protections.

---

## 2. Live Games

| Game | Engine | Description | Variance |
|---|---|---|---|
| **BlackRed** | PHP 8.4 | Binary colour prediction across 1–5 cards with escalating multipliers | Fast cycle, low variance at entry tier |
| **Heritage** | Python 3.12 | Culture-themed 5-of-9 board instant-win with secondary 5/90 national draw entry | Higher variance, retention loop |
| **Caged (BirdEscape)** | Pure PHP / Python | Interactive escape prediction mechanic with crash-style multiplier curve | Dynamic real-time risk/reward |

> **Engine Contract Rule:** All game engines are **pure functions** of their inputs `(seed, ticket_id, input) -> outcome`. Engines hold zero mutable state, move no money, generate no randomness, and never query house revenue.

---

## 3. Subdomain Architecture

Betplus is deployed using a decoupled, high-performance subdomain topology:

```
betplus.com.ng (Public Web Frontend)
    │
    ├── (Client API calls) ──> api.betplus.com.ng (Platform Backend API)
    │                               │
    │                               ├──> OPay APIs & Webhooks
    │                               │
    │                               └──> heritage-api.betplus.com.ng (Game Engine)
    │
    └── (Telco USSD turns) ──> ussd.betplus.com.ng (USSD Microservice)
                                    │
                                    └──> api.betplus.com.ng (Platform API)
```

| Subdomain | Description | Technology |
|---|---|---|
| **`betplus.com.ng`** | Player portal, interactive game clients, landing page, and operations console | Next.js (Static Export) |
| **`api.betplus.com.ng`** | Core platform API: identity, double-entry wallet, ticket lifecycle, taxes & OPay orchestrator | Laravel 11 / PHP 8.4 |
| **`ussd.betplus.com.ng`** | Telco aggregator gateway (Africa's Talking / Hubtel webhook receiver) | Lightweight PHP 8.4 |
| **`heritage-api.betplus.com.ng`** | Heritage game math engine (binds to loopback `http://127.0.0.1:8100` in production) | Python / FastAPI / Uvicorn |

---

## 4. Financial & Payment Structure

### Double-Entry Accounting
* **Sole Writer:** `WalletService` in `apps/platform` is the sole writer to `ledgerEntry`, `ledgerAccount`, and `playerWallet`.
* **Integer Kobo:** All currency values are stored as 64-bit integers (`BIGINT`) representing integer kobo (₦1.00 = 100 kobo). Floating-point arithmetic is prohibited.
* **Balanced Journals:** Every money movement balances to zero (`∑ debits - ∑ credits = 0`). Imbalances trigger instant P0 alerts.

### Dual-Balance System (`BP-AML-001`)
* **Play Balance (`PLAYER_PLAY`):** Funded exclusively by player deposits. Wagered across any game. Locked from direct withdrawal until **1× turnover** is achieved.
* **Winnings Balance (`PLAYER_WINNINGS`):** Credited immediately upon a winning ticket. Freely withdrawable at any time directly to the player's OPay wallet.

### OPay Rail Integration
* **Single MSISDN Anchor:** The user's phone number (`+234...`) serves as their Betplus account, verification anchor, and OPay wallet address.
* **Collections (Deposits):** Bank Account debit via OTP/PIN signed with **HMAC-SHA512**.
* **Payouts (Withdrawals & Auto-Disbursements):** Direct-to-wallet disbursements (`payoutType: OpayWalletNg`) signed with **RSA-SHA256** (2048-bit keys).
* **Float Circuit Breakers:** Tiered alerting on the merchant cash float (`OPAY_FLOAT`) with automatic queuing at critical limits to guarantee that a won prize is never lost.

---

## 5. Repository Layout

```text
├── apps/
│   ├── platform/            # Core Laravel 11 API (Auth, Wallet, Payouts, Admin)
│   ├── web/                 # Next.js frontend (Tailwind/Vanilla CSS, Static HTML5)
│   ├── ussd/                # USSD state machine & telco adapter
│   ├── engine-blackred/     # BlackRed pure-function PHP math engine
│   └── engine-heritage/     # Heritage culture & prize-table Python engine
├── docs/                    # Architecture blueprints, specifications & DDL
├── infra/                   # Nginx reverse proxy configs, systemd services, deployment scripts
├── .github/
│   └── workflows/
│       ├── ci.yml           # Continuous Integration test suites & static analysis
│       └── deploy.yml       # Production zero-downtime atomic cPanel deployment
├── Betplus_PRD.md           # Authoritative Product Requirements Document
└── project-context.md       # Engineering guardrails & system constraints
```

---

## 6. Local Development Setup

### Prerequisites
* **PHP** 8.2 or 8.4 with extensions: `pdo_mysql`, `openssl`, `mbstring`, `curl`, `bcmath`
* **Node.js** 20.x & **pnpm** 9.x
* **Python** 3.12+
* **Composer** 2.x

### Quickstart

1. **Clone the repository:**
   ```bash
   git clone https://github.com/PreciseK/betplus.git
   cd betplus
   ```

2. **Install root & frontend dependencies:**
   ```bash
   pnpm install
   ```

3. **Set up the Platform API:**
   ```bash
   cd apps/platform
   composer install
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   php artisan db:seed
   ```

4. **Run development servers:**
   * Platform API: `cd apps/platform && php artisan serve --port=8000`
   * Web Frontend: `cd apps/web && pnpm dev`
   * Heritage Engine: `cd apps/engine-heritage && uvicorn app.main:app --port=8100`

---

## 7. Automated Testing

All modules enforce rigorous automated test gates in CI:

```bash
# Frontend test suite (Vitest + React Testing Library)
pnpm --filter web test

# Platform test suite (Pest / PHPUnit)
cd apps/platform && php artisan test

# PHPStan static analysis
cd apps/platform && composer stan

# Python test suite
cd apps/engine-heritage && pytest
```

---

## 8. Deployment (cPanel / CI-CD)

Production deployments use **zero-downtime atomic symlinks** orchestrated by GitHub Actions ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)).

Every release is prepared in an isolated directory (`/home/user/betplus/releases/<timestamp>`) and switched atomically via symlink (`current`) in 1 millisecond, guaranteeing zero partial file loads or downtime during gameplay.

---

## 9. Security & Compliance

* **Secrets Management:** Private keys and database credentials are kept strictly out of source control.
* **Identity Vault:** BVN and NIN identifiers are stored in an independently encrypted identity vault schema (`REQ-ID-023`).
* **Responsible Gaming:** Real-time cooling-off periods, self-exclusion registers, and loss/deposit limits are enforced server-side.
