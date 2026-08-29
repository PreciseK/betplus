# Betplus / BlackRed — Project Overview

> Brownfield documentation of the system **as it exists today**, generated as input to the
> target architecture design. This document describes the current build, not the target.
> The target is defined by `Betplus_PRD.md`.

**Scanned:** 10 August 2026 · **Scan level:** Deep · **Repository type:** Multi-part (3 parts, single deployable)

---

## 1. What this is

A production PHP backend and web app for **BlackRed**, an instant binary colour-prediction
game, built for the Ghana market and settled through ANM mobile money. It is the codebase the
Betplus PRD proposes to refactor into a multi-game Nigerian platform.

The repository has **no version control** (`.git` absent) and **no CI configuration**.

## 2. Parts

| Part | ID | Root | Type | Language / runtime |
|---|---|---|---|---|
| Platform API | `platform-api` | `BlackRed/EngineAndServices/src` + `public/index.php` | backend | PHP 8.1+, PSR-4 (`BlackRed\`) |
| USSD channel | `ussd` | `BlackRed/EngineAndServices/ussd` | backend | PHP 8.1+, **procedural, no autoloader** |
| Web app | `web-app` | `BlackRed/EngineAndServices/public/app` | web | Static HTML + service worker, **no build step** |

All three deploy as one Apache document root. They are separate *parts* by code organisation
and runtime entry point, not by deployment unit.

## 3. Technology stack

| Category | Technology | Version | Notes |
|---|---|---|---|
| Language | PHP | ^8.1 | `declare(strict_types=1)` throughout `src/`, absent in `ussd/` |
| Web server | **Apache** | — | `.htaccess` in 6 locations; **PRD specifies Nginx** |
| Database | MySQL / MariaDB | — | PDO, `ext-pdo_mysql` |
| Logging | monolog/monolog | ^3.5 | |
| Config | vlucas/phpdotenv | ^5.6 | |
| HTTP framework | **None** — hand-rolled | — | Custom `Router`, `Pipeline`, `Container`, `Middleware` |
| Cache / queue | **None** | — | No Redis, no queue, no workers (`workers/` is empty) |
| Tests | Custom runner | — | `composer test` → `tests/run.php`, **which does not exist**; `ussd/tests/run.php` does |
| CI/CD | **None** | — | No `.github/`, no pipeline, no Dockerfile, no IaC |

**Architecture pattern:** layered service/API-centric monolith — front controller → middleware
pipeline → controller → service → PDO. The `ussd` part does not follow it.

## 4. Entry points

| Entry point | Serves |
|---|---|
| `public/index.php` | JSON API under `/api/*` (76 lines, boots `Bootstrap\App`) |
| `ussd/index.php` | USSD gateway webhook, own bootstrap (237 lines) |
| `ussd/callbacks/momo.php` | Mobile-money callback for USSD-initiated transactions |
| `public/app/*.html` | Static web app pages |

Two independent bootstraps against one database is the defining structural fact of this codebase.

## 5. Findings that shape the refactor

These are recorded here because they change the scope of the work, not merely its sequence.
Each is expanded in the linked document.

| # | Finding | Impact | Detail |
|---|---|---|---|
| **B-1** | **The game engine is not a pure function.** `GameEngineService::play()` opens a DB transaction, reads config, generates its own randomness, writes ledger entries, moves money and writes audit rows — in one 300-line method. | Engine Contract (§6) is a rewrite, not an extraction | [architecture-platform-api.md](./architecture-platform-api.md) |
| **B-2** | **Outcomes are governed by house revenue, not by a prize table.** The engine computes `netRevenueToday = losses − wins` and forces a guaranteed loss whenever projected payout exceeds a configured percentage of it, or whenever net revenue is negative. A losing card sequence is then constructed deliberately. | Certified prize tables, published odds and RTP modelling are incompatible with this mechanism as built | [architecture-platform-api.md](./architecture-platform-api.md) |
| **B-3** | **USSD is a second, independent implementation of the same game.** `ussd/lib/game.php` re-implements the multiplier table, the `fair_random` / `forced_loss` decision, the shuffle, the ledger writes and the daily summary update — in procedural PHP against the same tables. | Channel parity cannot be asserted; two engines must be reconciled or one deleted | [integration-architecture.md](./integration-architecture.md) |
| **B-4** | **The checked-in migrations do not build a working database.** Seven tables the code reads and writes are absent from `migrations/`; twelve tables defined in `migrations/` are never referenced by any code. | No clean environment can be provisioned from the repository | [data-models.md](./data-models.md) |
| **B-5** | **Live secrets are committed in the working tree.** `.env` and `ussd/config.php` carry database credentials, the app secret, ANM client/secret keys and Hubtel SMS credentials. | Rotation required before any repository is published or shared | [development-guide.md](./development-guide.md) |
| **B-6** | **No version control, no CI, no automated test gate.** | Every PRD release gate (§17.4) needs infrastructure that does not exist | [development-guide.md](./development-guide.md) |
| **B-7** | **Ghana market is compiled into the domain,** not configured: `pesewas` in ~40 column and variable names, `GHS` currency literals in SQL, `Africa/Accra` business timezone constant, `Support/GhanaPhone.php`, `PLAYER_PAYOUT` account naming, ANM and Hubtel as hard dependencies. | Currency and locale migration is schema-wide, not a config flag | [data-models.md](./data-models.md) |

## 6. Distance from the PRD target

| PRD requirement | Current state |
|---|---|
| `REQ-GEC-001` engine is a pure function | Engine owns money, state and randomness (B-1) |
| `REQ-GEC-003` engine never generates randomness | `random_int` / `random_bytes` called inside the engine |
| `REQ-GEC-020` prize tables are versioned configuration | Multipliers are a `private const` in two files (B-3) |
| `REQ-GEC-023` 95% RTP ceiling | Multipliers 2×/10×/20×/50×/100× against fair odds of 2/4/8/16/32 |
| `REQ-ARCH-001` single wallet writer | Three writers: `src/Wallet/*`, `ussd/lib/game.php`, `ussd/lib/deposit.php` |
| `REQ-HOST-003` Nginx + PHP-FPM | Apache + `.htaccess` |
| `REQ-HOST-005` supervised queue workers | `workers/` empty; no queue exists |
| `REQ-WAL-003` integer kobo | Integer pesewas, denomination in column names |
| Redis for cache/session | Not present; sessions and rate limits are DB rows |
| Heritage engine (Python) | Does not exist |
| Tax, geo, RG, float, back office | Do not exist |

**Reusable with modification:** the double-entry ledger shape (`account` / `wallet` /
`walletTransaction` / `ledgerEntry`), the middleware pipeline, the USSD finite-state-machine
pattern, the mobile-money name-lookup registration flow, RBAC tables.

**Not reusable:** the engine decision rule, the USSD duplicate implementation, the ANM and
Hubtel integrations, the currency layer.

## 7. Documentation map

- [Source Tree Analysis](./source-tree-analysis.md)
- [Architecture — Platform API](./architecture-platform-api.md)
- [Architecture — USSD](./architecture-ussd.md)
- [Architecture — Web App](./architecture-web-app.md)
- [Data Models](./data-models.md)
- [API Contracts](./api-contracts.md)
- [Integration Architecture](./integration-architecture.md)
- [Development Guide](./development-guide.md)
- [Deployment Guide](./deployment-guide.md)
