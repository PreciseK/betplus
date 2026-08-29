# Betplus — Documentation Index

> Primary entry point for AI-assisted development on this repository.
> Generated 10 August 2026 · deep scan · brownfield documentation of the **current** system.

---

## Project overview

- **Type:** multi-part repository, 3 parts, single Apache deployable
- **Primary language:** PHP 8.1+
- **Architecture:** layered monolith (hand-rolled micro-framework) + a separate procedural USSD app
- **Version control:** **none** — the repository is not under git
- **Target specification:** [`Betplus_PRD.md`](../Betplus_PRD%20(1).md) — §0–21, 1,978 lines

## Quick reference by part

| Part | Root | Type | Stack |
|---|---|---|---|
| **Platform API** (`platform-api`) | `BlackRed/EngineAndServices` | backend | PHP 8.1, PSR-4 `BlackRed\`, PDO/MariaDB, Monolog, phpdotenv. 16 routes, 10 controllers, 6 middleware |
| **USSD** (`ussd`) | `BlackRed/EngineAndServices/ussd` | backend | PHP 8.1 procedural, 23-state FSM, no autoloader, own config and DB layer |
| **Web App** (`web-app`) | `BlackRed/EngineAndServices/public/app` | web | 10 static HTML pages + service worker, no build step |

## Generated documentation

- [Project Overview](./project-overview.md) — what this is, the stack, and the seven findings that shape the refactor
- [Source Tree Analysis](./source-tree-analysis.md) — annotated tree, critical folders, entry points
- [Architecture — Platform API](./architecture-platform-api.md) — request lifecycle, layers, **the game engine and its decision rule**
- [Architecture — USSD](./architecture-ussd.md) — the state machine and what it duplicates
- [Architecture — Web App](./architecture-web-app.md) — page inventory, rewrite disposition
- [Data Models](./data-models.md) — schema, migration gaps, money model, currency coupling
- [API Contracts](./api-contracts.md) — all 16 endpoints, the play contract, distance from PRD §11
- [Integration Architecture](./integration-architecture.md) — how the parts connect, and the four things to verify before reuse
- [Development Guide](./development-guide.md) — setup, config, secrets, testing gaps
- [Deployment Guide](./deployment-guide.md) — current model vs PRD §5.5
- [`project-parts.json`](./project-parts.json) — machine-readable part and finding metadata

## Existing documentation found

| File | Note |
|---|---|
| `BlackRed/PRD.md` | Superseded product doc for the current Ghana build |
| `Betplus_Platform_PRD.md` | Earlier draft of the target specification |
| `BlackRed/EngineAndServices/ussd/README.md` | USSD channel notes |
| `OPay Payout API EN_NG_v2.9/` | Vendor documentation for the target payment rail (4 documents) |

## Start here

**If you are designing the target architecture:** read [Project Overview](./project-overview.md)
§5–6, then [Architecture — Platform API](./architecture-platform-api.md) §5, then
[Integration Architecture](./integration-architecture.md) §3.

**If you are planning the refactor sequence:** read [Data Models](./data-models.md) §2 and
[Deployment Guide](./deployment-guide.md) §5 — both contain work that blocks everything else.

**If you are about to touch production:** read [Development Guide](./development-guide.md) §4
and §8 first.

## The five things that most change the plan

1. **The engine decides outcomes from house revenue, not from a prize table** — so the Engine
   Contract is a rewrite and a product decision, not an extraction.
   ([architecture-platform-api.md](./architecture-platform-api.md) §5.2)
2. **USSD is a second implementation of that same engine**, writing the same ledger.
   ([architecture-ussd.md](./architecture-ussd.md) §4)
3. **Five entire migrations are missing — 003 through 007** — so there is no staging, no test
   database and no release gate until the schema is reconstructed. Candidate DDL exists at
   [reconstructed-ddl.sql](./reconstructed-ddl.sql), pending validation against production.
   ([data-models.md](./data-models.md) §2.1)
4. **Live secrets are in the working tree and there is no git history yet** — cheap to fix now,
   expensive later. ([development-guide.md](./development-guide.md) §4)
5. **The static egress IP is a Phase 0 blocker** — OPay IP-whitelists in both directions, so no
   payment work can be tested without it. ([deployment-guide.md](./deployment-guide.md) §3)
