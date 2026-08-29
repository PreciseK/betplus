---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
lastStep: 8
status: 'complete'
completedAt: '2026-08-10'
inputDocuments:
  - 'Betplus_PRD.md'
  - 'UX-Design.md'
  - 'design.md'
  - 'docs/index.md'
  - 'docs/project-overview.md'
  - 'docs/source-tree-analysis.md'
  - 'docs/architecture-platform-api.md'
  - 'docs/architecture-ussd.md'
  - 'docs/architecture-web-app.md'
  - 'docs/data-models.md'
  - 'docs/api-contracts.md'
  - 'docs/integration-architecture.md'
  - 'docs/development-guide.md'
  - 'docs/deployment-guide.md'
  - 'docs/project-parts.json'
  - 'OPay Payout API EN_NG_v2.9/OPay Payout API Developer Guide EN_NG_v2.9.md'
  - 'OPay Payout API EN_NG_v2.9/Payout integration process_v2.0.md'
  - 'OPay Payout API EN_NG_v2.9/How does a merchant generate rsa key pair.md'
  - 'OPay Payout API EN_NG_v2.9/OPay Merchant Dashboard User Guide_Nigeria.md'
inputDocumentsExcluded:
  - 'Buzzycash_Platform_PRD.md — superseded draft of the same target; excluded to avoid conflicting requirements'
  - 'BlackRed/PRD.md — describes the current Ghana build; superseded by docs/ brownfield scan'
workflowType: 'architecture'
project_name: 'Betplus'
user_name: 'PRECISE'
date: '2026-08-10'
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Workflow inputs

**Target specification:** `Betplus_PRD.md` — §0–21, 1,978 lines. Multi-game instant-win
platform for Nigeria (BlackRed + Heritage) across Web, React Native app and USSD, settled
exclusively through OPay.

**Brownfield context:** `docs/` — 13 documents from the deep scan of
`BlackRed/EngineAndServices`, recording the current PHP build, its three parts, and seven
findings that constrain the refactor.

**Vendor reference:** OPay Payout API v2.9 documentation set — normative for the payment
integration, including the two distinct signature schemes and the IP allowlist requirement.

**Design inputs:** `UX-Design.md` — Betplus UX Design Standard, including §4.4 back-office
navigation, §14 back-office UX and §15 measurement. `design.md` — Betplus Design System,
covering tokens, typography, component language and game visual boundaries. Both were supplied
after step 3 and are authoritative for the channel and back-office surfaces.

**Generated during this workflow:** `project-context.md` — 27 binding rules derived from the PRD,
loaded as a persistent fact for the remainder of the architecture process.

**Not found:** research documents, product brief.

---

## Project Context Analysis

### Requirements Overview

**Scale:** 396 unique requirements across 27 modules — **350 MUST**, 8 SHOULD.

| Module | Count | Module | Count |
|---|---|---|---|
| `REQ-HG` Heritage | 46 | `REQ-WAL` Wallet and ledger | 16 |
| `REQ-NFR` Non-functional | 40 | `REQ-ID` Identity and KYC | 16 |
| `REQ-COMP` Compliance | 28 | `REQ-USSD` · `REQ-PAY` · `REQ-GEC` · `REQ-BR` | 14 each |
| `REQ-SEC` Security | 27 | `REQ-TAX` · `REQ-PO` · `REQ-HOST` · `REQ-GEO` · `REQ-BO` | 11 each |
| `REQ-QA` Testing | 26 | `REQ-TKT` Ticket lifecycle · `REQ-APP` Mobile | 10 each |
| `REQ-RG` Responsible gambling | 20 | `REQ-RNG` · `REQ-NOT` · `REQ-FLOAT` · `REQ-CERT` · `REQ-WEB` · `REQ-DATA` · `REQ-ANL` · `REQ-ARCH` | 3–9 each |

Compliance, security and testing together account for 81 requirements — more than any single
functional domain. Heritage at 46 is the largest feature and carries the most outstanding
content and cultural work.

**Functional shape.** The platform owns identity and KYC, a double-entry wallet and ledger,
OPay collections and payouts, float management, a tax engine, geolocation and state
attribution, responsible gambling with external registry integration, USSD, notifications,
randomness and fairness, back office and per-state reporting. Games own outcome resolution and
nothing else, behind the Engine Contract. BlackRed (PHP) and Heritage (Python) are the first
two implementations.

**Non-functional drivers.** 1,000 tickets/s sustained and 4,000 burst; API p95 ≤ 400 ms on the
play path; engine `resolve` p95 ≤ 80 ms; 99.9% monthly uptime; RPO ≤ 1 minute, RTO ≤ 30
minutes; p95 payout settlement ≤ 90 s; zero unreconciled money-movement records at T+1.

### Scale and Complexity

- **Complexity: enterprise** — driven by regulatory surface area, financial correctness and
  third-party certification rather than by user volume.
- **Primary domain:** full-stack, backend-weighted — multi-channel clients over a service
  platform with pluggable game engines.
- **Estimated architectural components: ~20** — 11 platform services, 2 game engines, 3 channel
  clients, draw-partner bridge, back office, reporting, Fairness Service.

**This is a regulated financial system that happens to contain games.** The money path carries
more requirements and materially more risk than either game. The architecture is organised
around the ledger, not around gameplay.

### Technical Constraints and Dependencies

| Constraint | Architectural consequence |
|---|---|
| cPanel/WHM, single application server at launch (`REQ-HOST-001`, `REQ-HOST-008`) | Throughput targets must be met on one box; the scale-out path is rehearsed before launch, not improvised under load |
| Static egress IP under change control (`REQ-HOST-002`, `REQ-PAY-011`) | OPay IP-whitelists in both directions — an infrastructure blocker on the critical path, not a deployment detail |
| Python GIL (`REQ-NFR-034`, `REQ-HOST-004`) | Heritage deploys as multiple worker processes, never threads |
| OPay exclusive, behind an abstraction (`REQ-PAY-017`) | Single-rail business risk is mitigated only by the Payment Orchestrator seam holding |
| Verified NIN as a precondition for play (`REQ-ID-021`) | Exclusion registries key on NIN, so identity gates the entire acquisition funnel |
| 160-character USSD screens (`REQ-QA-013`) | Build-failing copy constraint in every locale, including longer Pidgin strings |
| Store approval for real-money gambling (`REQ-APP-006`, `REQ-APP-007`, C-10) | Both stores require review; Play approval for Nigeria is unconfirmed |
| Engine endpoints on loopback only (`REQ-SEC-011`, `REQ-HOST-003`) | The reverse-proxy topology is a security boundary, not just routing |
| No federal regulator; 36 states plus FCT | Jurisdiction is a first-class domain concern threaded through ticket, ledger, tax and reporting |

**Brownfield constraints** (`docs/`): the inherited engine resolves outcomes from house daily
revenue rather than a prize table; USSD is a second implementation writing the same ledger;
the migrations do not build a working database; secrets sit in the working tree. The first two
mean the BlackRed engine is written fresh against the Engine Contract rather than extracted —
made straightforward by the PRD's now-specified multipliers (§8.4).

**Third-party dependencies pending confirmation:** twelve items, C-01 to C-12. C-01 (licensing
route) blocks everything; C-03 (OPay limits, SLA, MDR) sets the float model; C-05 and C-08
determine whether USSD state attribution and the fail-closed registry design are viable as
specified.

### Cross-Cutting Concerns

Idempotency · state attribution · tax computation · audit and deterministic replay ·
responsible-gambling gating · fail-closed dependency handling · integer-kobo denomination ·
maker-checker approval · observability and correlation · PII vaulting.

Every one of these intersects ticket creation. **`REQ-TKT-002`'s ten-step atomic transaction is
the architectural centre of the system** — resolve jurisdiction, check registry, check RG
limits, check game registry, validate stake, reserve from Play Balance, obtain seed, call the
engine, persist, lock. Get that boundary right and most of the rest follows from it.

Two dependencies **fail closed** — geolocation and the exclusion registry. Their availability
target therefore equals the play path's own, which makes them architectural components with
redundancy requirements rather than ordinary integrations.

Certification and replay shape the data model directly: RNG lab certification, byte-identical
deterministic replay, WORM seed records with hash chaining, write-once outcome and jurisdiction
rows, versioned prize tables and tax rulesets. Auditability here is a structural property, not
a logging concern.

---

## Starter Template Evaluation

### Primary Technology Domain

**Polyglot monorepo, backend-weighted** — four independently-initialised workspaces rather than
one starter. Most starter-level decisions are already fixed by the PRD (§5.4, `REQ-HOST-001`,
`003`, `004`, `REQ-APP-001`, `002`, `REQ-ARCH-001`): PHP 8.1+ under PHP-FPM, Python 3.10+ under
Uvicorn multi-process workers, Nginx, MariaDB 10.6+, Redis with AOF, React Native with Hermes, and the
PHP Wallet Service as sole ledger writer.

Genuinely open, and decided here: the PHP framework, the Python service framework, Expo versus
bare React Native, the web stack, and monorepo tooling.

### Version verification

Verified by web search on 10 August 2026:

| Component | Verified | Consequence |
|---|---|---|
| Laravel | **13.24.0** — requires **PHP 8.3+** (range 8.3–8.5); bug fixes to Sep 2027, security to Mar 2028 | **Supersedes the PRD's "PHP 8.1+"** — see below |
| Laravel 12 (fallback) | PHP 8.2–8.5; bug-fix window closed 13 Aug 2026 | Not a viable fallback |
| PHP | 8.1 end-of-life; 8.2 security-only to 31 Dec 2026; **8.4** security to 31 Dec 2028 | **Target PHP 8.4** |
| cPanel EasyApache 4 | Ships `ea-php83` and `ea-php84` | No platform gap; Laravel 13 runs |
| Filament | **v5.7.6**, requires PHP 8.2+ and Laravel 11/12/13 | Compatible; Laravel is the binding constraint |
| Next.js | **16.3** (3 Aug 2026), requires **Node 20.9+** | Static export fully supported in App Router |
| FastAPI | **0.141.1**, Python 3.10+ | — |
| Expo | SDK 57.0.11 → React Native 0.86, React 19.2 | — |

**Two decisions changed by this verification.**

**PHP 8.4, not 8.1.** Laravel 13 requires 8.3 minimum, but the stronger argument is independent
of framework choice: PHP 8.1 is end-of-life and 8.2 is security-only until the end of 2026.
A licensed real-money operator running an unsupported interpreter is an audit finding on its
own terms. cPanel EasyApache 4 provides 8.4, so there is no platform obstacle. Recorded as
`REQ-HOST-012`.

**Uvicorn multi-process, not Gunicorn.** FastAPI's official deployment guidance no longer
mentions Gunicorn, and `uvicorn.workers.UvicornWorker` has been deprecated since Uvicorn 0.30.0
and split into a separate `uvicorn-worker` package. The Heritage engine deploys as
`fastapi run --workers N` or `uvicorn --workers N` behind Nginx. `REQ-HOST-004`'s
multi-process-not-threads requirement is unchanged; only the mechanism is.

**Static export constraints confirmed.** Next.js 16.3 supports `output: 'export'` in the App
Router including Server Components, `generateStaticParams` and static route handlers. It
excludes Server Actions, `proxy` (Next 16's replacement for middleware), `cookies()`, ISR and
the default image loader. None of these were relied upon — every mutation goes to the Laravel
API by `REQ-ARCH-001` — so the exclusions cost nothing here, but they do close the door on
Server Actions permanently while static export is in force.

**One verification gap remains.** cPanel's own documentation was unreachable (404s across
`docs.cpanel.net`); EasyApache PHP availability is corroborated from third-party evidence
rather than a cPanel-owned page. PHP 8.3 and 8.4 are well-attested; **PHP 8.5 on EA4 is
single-sourced and should not be planned around.** Separately, if Imunify360 Hardened PHP is
installed on the host it replaces cPanel's PHP packages and can break EasyApache installs of
new versions — check before the interpreter upgrade.

### Starter Options Considered

**PHP platform + BlackRed engine.** Laravel 13 selected over Symfony 8.1/7.4 LTS, Slim 4, and
retaining the hand-rolled framework. The deciding factor is not preference: the inherited
codebase's four structural gaps — no queue, no working migrations, no test infrastructure, no
back office — are each solved infrastructure in Laravel and each a substantial hand-build
otherwise. Roughly thirty requirements land on capabilities Laravel already ships: supervised
queue workers with Horizon monitoring (`REQ-HOST-005`, `REQ-NFR-046`), migrations that
structurally prevent the inherited schema drift (finding B-4), Filament or Nova against the
eleven back-office requirements (§7.10), and first-class Pest/PHPUnit for the ≥85% coverage
gate (§17.1). Symfony's stricter domain modelling and LTS cadence are real advantages for a
regulated system with a long compliance life, and it remains the credible alternative.

**Heritage engine.** FastAPI with Uvicorn's own multi-process worker manager per
`REQ-HOST-004`. Three endpoints, no state, no database. Pydantic models enforce the Engine
Contract schema at the boundary and generate the `describe` payload rather than duplicating it.
The dependency surface is deliberately minimal because `REQ-QA-004` requires static analysis
proving the engine performs no database access, no outbound network calls and no filesystem
writes. No starter template — scaffolding a few hundred lines would add more than it removes.

**Mobile.** Expo SDK 57 with Continuous Native Generation (`expo prebuild`) and EAS Build, with
over-the-air updates disabled per `REQ-APP-005`. Disabling OTA removes Expo's headline feature,
so the choice rests on the remainder: EAS Build removes the macOS hardware requirement for iOS
releases, and the four native modules required by `REQ-APP-003` — certificate pinning, root and
jailbreak detection, mock-location detection, secure storage — are cleaner to author against
the Expo Modules API with reproducible native output than as hand-managed bare RN project
files. Bare React Native remains viable at the cost of the macOS dependency.

**Web.** **Next.js with TypeScript, `output: 'export'`** — static build served directly by
Nginx.

> Decision recorded on the user's instruction, in preference to the Vite + React
> recommendation. The architectural objection was never to Next.js itself but to introducing a
> Node runtime into a topology `REQ-HOST-003` defines as Nginx → PHP-FPM and Passenger only.
> Static export removes that objection completely: the build output is static assets, the
> topology is unchanged, and the team keeps Next.js and TypeScript with file-based routing and
> App Router conventions.

What static export forgoes costs little here. Every meaningful screen sits behind
authentication and reads live wallet and game state from the JSON API, so server rendering buys
nothing; API routes are precluded by `REQ-ARCH-001`; geo and RG gating run server-side at
ticket creation (`REQ-TKT-002`) rather than in edge middleware; there is no CMS content to
revalidate. Public marketing pages are pre-rendered at build time, so SEO improves rather than
degrades. `next/image` needs a custom loader or pre-optimised assets. `REQ-WEB-002`'s budgets
(FCP ≤ 2.5 s on 3G, first-play payload ≤ 2.5 MB) are met through route-level code splitting,
and `REQ-WEB-004`'s CSP, HSTS and `X-Frame-Options` are set at Nginx exactly as specified.

Moving to a Node runtime later is a configuration change plus infrastructure work rather than a
rewrite, so full SSR stays available — but it must be taken deliberately, with the memory
footprint and process-supervision cost priced against a single application server already
targeting 1,000 tickets/s.

**Rejected: Next.js hosted on Vercel** with the API on the VPS. Not on technical grounds — on
C-11 and `REQ-COMP-045`. Serving the web tier from a US-edge CDN moves player data across
borders and requires a documented lawful basis with Legal sign-off before launch. Solvable, but
it converts a technical choice into a compliance dependency.

**Monorepo tooling.** Plain monorepo with per-workspace native tooling — Composer for PHP, uv
for Python, pnpm workspaces for JavaScript, and a root task runner for cross-cutting commands.
Nx and Turborepo are JavaScript-centric; they would manage two of five workspaces and add a
layer over the three that matter most.

### Initialization Commands

```bash
# Platform + BlackRed engine
composer create-project laravel/laravel apps/platform

# Heritage engine
uv init apps/engine-heritage && uv add fastapi uvicorn pydantic

# Mobile
npx create-expo-app@latest apps/mobile
cd apps/mobile && npx expo prebuild

# Web
npx create-next-app@latest apps/web --typescript --app --eslint
# then set output: 'export' in next.config.ts
```

Exact flags and pinned versions are re-verified at execution time.

**Note:** project initialization using these commands should be the first implementation story.

---

## Core Architectural Decisions

### Decision Priority Analysis

**Critical — block implementation:** D-01 ORM boundary · D-02 migration authority · D-05 token
mechanism · D-06 identity vault isolation · D-08 engine call placement · D-10 idempotency.

**Important — shape the architecture:** D-03 Redis usage · D-04 partitioning · D-07 engine
boundary auth · D-09 async boundary · D-11 state management · D-12 code sharing · D-13
animation · D-14 CI gate · D-15 deployment · D-16 secrets · D-17 monitoring.

**Deferred post-launch:** read replicas (`REQ-NFR-031`, not needed on the single-server launch
topology) · second payment provider behind the Payment Orchestrator · multi-partner draw
routing (`REQ-HG-042`) · Yoruba/Igbo/Hausa localisation.

**Already fixed by the PRD, recorded for completeness:** MariaDB 10.6+ and Redis · MSISDN and
OTP identity with three KYC tiers · REST `/v1` with `Idempotency-Key`, RFC 7807 errors and
`X-Request-Id` · rate limiting per IP, MSISDN and device · Nginx to PHP-FPM and Passenger on
cPanel/WHM · Monolog structured JSON with correlation IDs · AES-256 at rest and TLS 1.2+.

### D-08 — Engine call placement (the consequential decision)

`REQ-TKT-002` specifies ten steps in "a single transaction", with the engine called over HTTP
at step 8 — between reserving the stake and persisting the ticket. Read literally, that holds a
`FOR UPDATE` lock on the player's wallet row across a network round trip, against a target of
1,000 tickets/s.

| Option | Trade-off |
|---|---|
| A — literal reading, engine call inside the transaction | Simplest and matches the PRD text; atomic rollback is trivial. Locks held for the engine's 80 ms p95, so any engine latency spike becomes database lock contention |
| **B — resolve before the transaction (selected)** | Seed and engine call first, then open the transaction to reserve, persist and lock. Lock scope drops to microseconds |
| C — reserve, commit, resolve, settle in two transactions | Best throughput, worst complexity; introduces a real intermediate state needing its own recovery path |

**Decision: B.** Every invariant the PRD cares about is preserved — nothing persists and no
money moves unless the whole sequence succeeds. Because the engine is a pure function
(`REQ-GEC-001`), calling it before the transaction is safe by construction, and an outcome
discarded because the reserve failed costs nothing since it was never recorded. The engine call
is made idempotent on `ticket_id` so a retry cannot produce a second outcome.

> **Resolved: the PRD has been amended to match.** `REQ-TKT-002` now specifies two phases —
> eligibility and resolution with no locks held, then commitment in a single transaction — and
> adds `REQ-TKT-011` (engine call idempotent on `ticket_id`; an outcome whose transaction does
> not commit is discarded and has no observable existence) and `REQ-TKT-012` (RG limits, KYC
> tier and Play Balance re-asserted inside the transaction). Architecture and specification now
> agree; this is no longer a deviation.
>
> The re-assertion requirement was surfaced by this decision and is a genuine correctness
> improvement independent of it: a concurrent ticket on another channel can consume limit
> headroom between an eligibility check and a commit, whichever ordering is used.

### Data Architecture

**D-01 ORM boundary.** Eloquent for identity, configuration, catalogue and back office. The
Wallet Service uses the raw query builder behind repository interfaces, because the ledger
requires explicit `FOR UPDATE` scoping and an ORM's lazy loading and implicit N+1 behaviour are
precisely what must not appear in the money path.

**D-02 Migration authority.** Laravel migrations are the sole source of truth, with a CI check
that fails the build if the live schema diverges from a fresh migrate. This is the structural
remedy for finding B-4 — it makes "the schema only exists in production" impossible rather than
merely discouraged.

**D-03 Redis usage.** Sessions, rate-limit buckets, USSD FSM state, exclusion-registry cache
(15-minute TTL per `safeplay_cache_ttl`), float balance snapshot, game registry configuration.
**Balances are never held in Redis** — they are ledger-derived with a transactional cache
column and optimistic concurrency, per `REQ-WAL-002`.

**D-04 Partitioning.** Monthly range partitions on `ticket`, `ledgerEntry` and `taxDeduction`
from day one, with `ledgerEntry` additionally indexed on `stateCode` for per-state remittance
queries (`REQ-NFR-032`). Defining partitions on empty tables is free; retrofitting them onto a
live ledger is not.

### Authentication and Security

**D-05 Token mechanism.** Laravel Sanctum — `HttpOnly`, `Secure`, `SameSite=Lax` cookies for
web; bearer tokens for mobile. **Refresh-token rotation with reuse detection (`REQ-ID-026`) is
not provided by Sanctum and requires custom implementation** — scoped explicitly rather than
discovered during build.

**D-06 Identity vault.** NIN and BVN live in a separate MariaDB schema with its own credentials,
reachable only by a dedicated identity service. Not merely a separate table: `REQ-ID-023`
requires independent access control, which is enforceable through schema grants and is
otherwise only convention.

**D-07 Engine boundary.** Loopback binding plus mTLS via Nginx client certificates
(`REQ-SEC-011`). Fallback if mTLS proves awkward under cPanel: loopback binding plus a shared
secret header — weaker, and to be recorded as an accepted deviation if adopted.

### API and Communication Patterns

**D-09 Async boundary.** Queued through Redis with Horizon: payout dispatch, notifications,
draw-partner submission, callback processing, settlement events. Synchronous: everything on the
play path. Workers run supervised under systemd, never cron (`REQ-HOST-005`), with liveness
alerting (`REQ-NFR-046`).

**D-10 Idempotency.** Key and response body cached in Redis with a 24-hour TTL, backed by a
uniqueness constraint on the durable record. A replay returns the original response rather than
re-executing (`REQ-WAL-042`, `REQ-QA-008`).

### Frontend Architecture

**D-11 State management.** TanStack Query for server state on both web and mobile; Zustand for
local UI state. Wallet balance, ticket state and responsible-gambling status are server state
and are never mirrored into a client store.

**D-12 Code sharing.** A generated TypeScript types package and a shared API client are shared
between web and mobile. **UI components are not shared.** The reveal interaction differs
genuinely between platforms, and a shared component layer spanning Next.js and React Native
reliably degrades both.

**D-13 Animation.** Reanimated with the native driver for the Heritage reveal. `REQ-APP-010`'s
≥30 fps on a 2 GB Android 10 device is the acceptance target, not an aspiration.

### Infrastructure and Deployment

**D-14 CI.** GitHub Actions. The merge gate runs PHPStan, PHPUnit with coverage thresholds, the
prohibited-randomness check (`REQ-QA-005`), USSD screen-length validation (`REQ-QA-013`), the
RTP ceiling test (`REQ-QA-002`) and secret scanning (`REQ-SEC-005`).

**D-15 Deployment.** Deployer, producing atomic symlinked releases with rehearsed rollback
(`REQ-HOST-011`, `REQ-QA-026`).

**D-16 Secrets management.** SOPS with age — encrypted files in the repository, no vendor and no
runtime dependency, at the cost of more manual rotation. Selected as the launch default for a
single-VPS deployment. _Taken as the default in the absence of a stated preference; revisit
when the estate exceeds one server, at which point Infisical, Doppler or Vault become
proportionate._

**D-17 Monitoring and error tracking.** Self-hosted error tracking, with Prometheus and Grafana
for metrics on the application server. _Taken as the default in the absence of a stated
preference._ Sentry as a hosted service is the more capable option but engages **C-11 and
`REQ-COMP-045`**, because stack traces and request context can carry player data across
borders; adopting it requires aggressive scrubbing plus a documented and approved transfer
basis.

### Decision Impact Analysis

**Implementation sequence.** D-02 migrations and D-06 vault isolation come first, because both
are schema-shaped and expensive to retrofit. D-04 partitioning must land with the initial
schema. D-01 and D-08 then govern the Wallet Service and ticket pipeline, which is the
system's critical path. D-05, D-07, D-10 and D-14 gate the first externally reachable release.
D-16 is required before any real OPay credential exists. D-11 to D-13 are independent of the
backend sequence and can proceed in parallel once the API contract is fixed.

**Cross-component dependencies.** D-08 determines the Wallet Service interface, which
determines the Engine Contract client, which determines engine conformance testing. D-02
determines whether staging can exist at all, which gates every test in §17 and therefore every
release gate in §17.4. D-06 constrains the identity service boundary and, through it, the
exclusion-registry integration, since matching keys on NIN. D-16 gates the OPay integration
because RSA private keys cannot enter source control (`REQ-SEC-005`, `REQ-PAY-012`). D-17
interacts with C-11, the same unresolved data-residency item that governs hosting location.

---

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

**19 critical conflict points identified.** The highest-value ones sit at language boundaries,
where the same money concept crosses PHP, Python and TypeScript. The PRD is internally
consistent on naming but not obviously so — an agent reading one section in isolation will
guess the others wrong.

### Naming Patterns

| Layer | Convention | Example |
|---|---|---|
| MariaDB columns | camelCase (per §11 data model) | `stakeKobo`, `ticketRef`, `attributionConfidence` |
| MariaDB tables | camelCase singular | `ticket`, `ledgerEntry`, `taxDeduction` |
| PHP properties and methods | camelCase | `$stakeKobo` |
| JSON over the wire | **snake_case** (per §12 API examples) | `stake_kobo`, `net_prize_kobo` |
| Engine Contract payloads | snake_case (per §6.2) | `ticket_id`, `outcome_tier` |
| Python | snake_case | `stake_kobo` |
| TypeScript | camelCase, mapped in the API client | `stakeKobo` |
| Error codes | SCREAMING_SNAKE_CASE (per §12.7) | `TURNOVER_NOT_MET` |
| Analytics events | snake_case (per §15.1) | `ticket_settled` |
| Domain event classes (PHP) | PascalCase | `TicketSettled` |
| REST resources | plural, `/v1/tickets` | |

**Convention mapping occurs in exactly two places:** the PHP API resource layer and the
TypeScript API client. A third mapping site is a defect, not a style choice.

### Money Patterns

1. Every money identifier ends in `Kobo` or `_kobo`. No bare `amount`, `price`, `value` or
   `total`. If it is money and not named for kobo, it is wrong.
2. Integer only, everywhere — PHP `int`, Python `int`, MariaDB `BIGINT`. No float, no decimal,
   no string, no arbitrary-precision library.
3. **Clients never compute money.** TypeScript formats and displays; it never sums, applies a
   multiplier or derives a net. Every figure the player sees is server-supplied, which removes
   floating-point risk from the client entirely.
4. `refNumber` is at most 32 characters and numeric-safe, because OPay caps `merchantOrderNo`
   at 32 digits (`REQ-WAL-042`). Format: two-character prefix followed by digits only. The
   inherited `STK-a1b2c3d4e5f6` format is alphanumeric and would be rejected.

### Time Patterns

Store UTC · transmit ISO 8601 with `Z` · render West Africa Time. Database columns are UTC
`DATETIME`; JSON carries `timestamp_utc` (`REQ-ANL-001`); display formats to Africa/Lagos
(`REQ-NFR-053`).

**Business date is WAT-based, not UTC-based.** Daily reconciliation, per-state remittance
periods and draw calendars all use WAT day boundaries; a UTC-day query returns the wrong set for
one hour of every day. A single shared `businessDate()` helper is the only permitted source.

### Structure Patterns

| Concern | Rule |
|---|---|
| PHP tests | `tests/Unit`, `tests/Feature`, `tests/Contract` — not co-located |
| Python tests | `tests/` mirroring module layout |
| TypeScript tests | Co-located `*.test.ts` beside the unit under test |
| Code organisation | **By feature and domain, never by type** — `Wallet/`, `Ticket/`, `Tax/`, not `Services/`, `Repositories/` |
| Shared utilities | `Support/` holds genuinely cross-domain code only; domain logic never goes there |

### Format Patterns

**Enums.** Use `VARCHAR` columns with application-level enums rather than MySQL `ENUM`. Ticket
states, game statuses, tier codes and account types all evolve, and every `ENUM` change is an
`ALTER TABLE` against a partitioned money table — precisely the migration that must not run
under load. This is a deliberate departure from the inherited schema, which uses `ENUM`
extensively.

**Errors.** RFC 7807 with a stable `code`. The code is the contract; the human-readable message
may change freely.

### Communication Patterns

Domain events are PascalCase PHP classes (`TicketSettled`, `PayoutDispatched`); analytics event
names are snake_case per §15.1; queue jobs are PascalCase classes dispatched to named Redis
queues by priority, with payout and callback processing on higher-priority queues than
notifications.

### Process Patterns

**Never swallow an error in the money path.** No empty catch, no default-on-failure. `REQ-GEO-005`
and `REQ-RG-014` fail *closed* — an exception caught in a way that allows play to proceed is a
licence-condition breach rather than a bug.

**Redaction is structural.** MSISDN logs as last four digits only; NIN and BVN never appear in
any log, at any level, in any environment. The logger enforces this centrally rather than each
call site remembering.

### Cross-Language Contract Testing

The highest-value pattern defined here. The Engine Contract conformance suite is a **shared JSON
fixture set, stored once, executed against both engines**:

```
packages/engine-contract/
├── schema/      # request and response JSON Schema
└── fixtures/    # (seed, input) → expected output, per game
```

The PHP and Python suites both read those fixtures, so `REQ-QA-003` determinism and
`REQ-GEC-001` purity become one test executed twice rather than two tests that drift apart.
This is what makes the design test — "a third game is an adapter plus configuration" —
verifiable rather than aspirational.

### Enforcement Guidelines

**All AI agents MUST:**

1. Read `project-context.md` before writing code; its 27 rules override style preference.
2. Name every money field for kobo and use integers only.
3. Map naming conventions only at the two designated boundaries.
4. Fail closed on geolocation and exclusion-registry checks — never catch-and-continue.
5. Never write to `ledgerEntry`, `wallet` or `walletTransaction` outside the Wallet Service.
6. Never call a prohibited randomness function (`REQ-RNG-008`, `REQ-RNG-009`).

**Enforced by CI rather than review:** PHPStan custom rules for money-path typing and the
ledger-writer restriction; a grep gate for prohibited randomness; a schema-drift check against a
fresh migrate; USSD screen-length validation; secret scanning.

### Pattern Examples

```php
// ANTI-PATTERN — bare name, float arithmetic, direct ledger write
$total = $stake * 1.85;
DB::table('ledgerEntry')->insert([...]);

// CORRECT — kobo-named integers, movement through the Wallet Service
$netPrizeKobo = $this->tax->applyWithholding($grossPrizeKobo, $jurisdiction);
$this->wallet->credit($playerId, Balance::WINNINGS, $netPrizeKobo, $refNumber);
```

```ts
// ANTI-PATTERN — client deriving money
const net = gross - gross * 0.05;

// CORRECT — every figure server-supplied
const { grossPrizeKobo, taxAmountKobo, netPrizeKobo } = settlement;
```

---

## Project Structure & Boundaries

### Two structural decisions that shape the tree

**USSD is a separate deployable, not a module inside the platform.** Brownfield finding B-3 is
that the current USSD application re-implements the game engine and writes the ledger directly.
If USSD lives inside the platform codebase it can reach the Wallet Service by direct method
call, and "channels hold no money logic" reverts to convention — precisely the failure being
corrected. As a separate application reachable only through `/v1`, the constraint is
structural. The cost is one additional PHP-FPM pool and an HTTP hop; USSD's 2-second screen
budget absorbs it against the API's 400 ms.

**The BlackRed engine is a standalone service with no database credentials.** `REQ-GEC-002`
states engines do not touch the database and `REQ-ARCH-002` requires grants to enforce it. An
engine implemented as a route group inside the platform shares its connection, and purity
becomes a code-review promise rather than a property of the deployment.

### Complete Project Directory Structure

```
betplus/
├── README.md
├── project-context.md                 # 27 binding rules — agents read this first
├── Makefile                           # cross-workspace task runner
├── pnpm-workspace.yaml
├── .gitignore                         # .env, vendor/, node_modules/, *.zip, error_log
├── .sops.yaml                         # SOPS + age configuration (D-16)
├── .github/
│   └── workflows/
│       ├── ci.yml                     # PHPStan · PHPUnit · pytest · tsc · secret scan
│       ├── contract.yml               # Engine Contract conformance, both engines
│       ├── gates.yml                  # RTP ceiling · prohibited RNG · USSD length · schema drift
│       └── deploy.yml                 # Deployer, manual approval for production
│
├── apps/
│   ├── platform/                      # ══ Laravel 13 — THE PLATFORM ══
│   │   ├── app/
│   │   │   ├── Domain/                # organised by domain, never by type
│   │   │   │   ├── Identity/          # §7.2 · REQ-ID-001..027
│   │   │   │   │   ├── Actions/       #   RegisterPlayer, VerifyOtp, UpgradeKycTier
│   │   │   │   │   ├── Models/        #   Player, KycRecord
│   │   │   │   │   ├── Vault/         #   NIN/BVN — separate schema connection (D-06)
│   │   │   │   │   └── Contracts/     #   IdentityVendor interface
│   │   │   │   ├── Wallet/            # §7.4 · SOLE LEDGER WRITER · REQ-ARCH-001
│   │   │   │   │   ├── WalletService.php        # reserve · capture · release · credit
│   │   │   │   │   ├── Ledger/                  # raw query builder only (D-01)
│   │   │   │   │   │   ├── LedgerWriter.php     #   the only class that INSERTs
│   │   │   │   │   │   └── JournalBalancer.php  #   asserts debits − credits = 0
│   │   │   │   │   ├── Turnover/                #   1× turnover, FIFO per deposit
│   │   │   │   │   └── Models/                  #   Account, Wallet, WalletTransaction
│   │   │   │   ├── Ticket/            # §7.11 · the ten-step pipeline
│   │   │   │   │   ├── CreateTicket.php         # orchestrates the D-08 ordering
│   │   │   │   │   ├── SettleTicket.php
│   │   │   │   │   ├── AutoSettleAbandoned.php  # REQ-TKT-006
│   │   │   │   │   └── States/                  # CREATED→FUNDED→…→PAID
│   │   │   │   ├── Games/             # §6.3 registry and router
│   │   │   │   │   ├── GameRegistry.php
│   │   │   │   │   ├── EngineClient.php         # Engine Contract HTTP client, mTLS
│   │   │   │   │   ├── PrizeTable/              # versioned config + publication gate
│   │   │   │   │   └── Heritage/                # board, reveals, second chance
│   │   │   │   ├── Payments/          # §7.3 · OPay collections
│   │   │   │   │   ├── PaymentOrchestrator.php  # provider-agnostic (REQ-PAY-017)
│   │   │   │   │   └── Providers/Opay/
│   │   │   │   │       ├── OpayCollectionsClient.php
│   │   │   │   │       ├── HmacSha512Signer.php # collections ONLY
│   │   │   │   │       └── RsaSha256Signer.php  # payouts ONLY (REQ-PAY-010)
│   │   │   │   ├── Payout/            # §7.5 · payout engine and float
│   │   │   │   │   ├── DispatchPayout.php
│   │   │   │   │   ├── Float/                   # snapshot · thresholds · alerts
│   │   │   │   │   └── KoboNairaConverter.php   # REQ-PO-012 — the 100× trap
│   │   │   │   ├── Tax/               # §7.6 · WHT · GGR · CIT
│   │   │   │   │   ├── TaxEngine.php
│   │   │   │   │   └── Rulesets/                # effective-dated, per state
│   │   │   │   ├── Jurisdiction/      # §7.7 · geolocation and state attribution
│   │   │   │   │   ├── AttributionService.php   # multi-signal, fails closed
│   │   │   │   │   ├── Signals/                 # GPS · network · IP · USSD · KYC
│   │   │   │   │   └── LicenceFootprint.php
│   │   │   │   ├── ResponsibleGaming/ # §7.9
│   │   │   │   │   ├── Limits/ · CoolOff/ · SelfExclusion/ · RealityCheck/
│   │   │   │   │   └── Registries/              # written for N registries (REQ-RG-017)
│   │   │   │   │       ├── RegistryClient.php   #   interface
│   │   │   │   │       └── SafePlayLagos.php    #   first implementation
│   │   │   │   ├── Fairness/          # §7.1 · RNG
│   │   │   │   │   ├── SeedIssuer.php           # CTR_DRBG, one seed per ticket
│   │   │   │   │   └── SeedChain.php            # WORM, hash-chained
│   │   │   │   ├── Notifications/     # §7.8 · SMS · push · inbox
│   │   │   │   └── Compliance/        # §14.2 · AML monitoring · NFIU reporting
│   │   │   ├── Http/
│   │   │   │   ├── Controllers/V1/    # player API
│   │   │   │   ├── Controllers/Internal/  # callbacks · health · registry sync
│   │   │   │   ├── Resources/         # ◄── camelCase → snake_case boundary #1
│   │   │   │   └── Middleware/        # auth · idempotency · rate limit · request id
│   │   │   ├── Filament/              # §7.10 back office panels
│   │   │   ├── Jobs/                  # queued: payout · notify · draw · callback
│   │   │   ├── Console/Commands/      # reconcile · registry sweep · float snapshot
│   │   │   └── Support/               # Money · BusinessDate · RefNumber
│   │   ├── database/
│   │   │   ├── migrations/            # ◄── SOLE SCHEMA AUTHORITY (D-02, fixes B-4)
│   │   │   ├── factories/
│   │   │   └── seeders/
│   │   ├── config/                    # game · float · tax · rg · opay · engines
│   │   ├── routes/
│   │   │   ├── v1.php
│   │   │   ├── internal.php
│   │   │   └── console.php
│   │   ├── tests/
│   │   │   ├── Unit/
│   │   │   ├── Feature/
│   │   │   ├── Contract/              # executes packages/engine-contract fixtures
│   │   │   └── Invariants/            # ledger balance · no negative · idempotency
│   │   └── public/index.php
│   │
│   ├── engine-blackred/               # ══ PURE FUNCTION · NO DB CREDENTIALS ══
│   │   ├── src/
│   │   │   ├── Engine.php             # resolve · replay · describe
│   │   │   ├── PrizeTable.php         # 1.85× 3.60× 7.00× 13.50× 26.00× (§8.4)
│   │   │   ├── DeckDraw.php           # seed → sequence, deterministic
│   │   │   └── Digest.php             # sha256 of the canonical outcome
│   │   ├── public/index.php           # binds 127.0.0.1 only
│   │   └── tests/
│   │
│   ├── engine-heritage/               # ══ PURE FUNCTION · Python · NO DB ══
│   │   ├── src/engine_heritage/
│   │   │   ├── main.py                # FastAPI, three routes
│   │   │   ├── schemas.py             # Pydantic — generates /describe
│   │   │   ├── board.py               # 9 of 90, without replacement
│   │   │   ├── reveal.py              # consistent reveal permutation
│   │   │   └── prize_table.py
│   │   ├── pyproject.toml             # uv · fastapi · uvicorn · pydantic
│   │   ├── uvicorn.conf.py            # multi-PROCESS workers, never threads (REQ-HOST-004)
│   │   └── tests/
│   │
│   ├── ussd/                          # ══ CHANNEL ADAPTER · NO MONEY LOGIC ══
│   │   ├── app/
│   │   │   ├── StateMachine/
│   │   │   │   ├── StateRegistry.php  # source of truth for which states exist
│   │   │   │   └── States/            # Entry · MainMenu · Play* · Dep* · Wd* · Rg*
│   │   │   ├── Screens/               # ≤160 chars, English + Pidgin (REQ-QA-013)
│   │   │   ├── Session/               # Redis primary, ussdSession mirror for audit
│   │   │   ├── Gateway/               # aggregator signature verification
│   │   │   └── PlatformClient.php     # ◄── the ONLY route to money: /v1 over HTTP
│   │   └── tests/ScreenLength/        # build-failing assertion
│   │
│   ├── web/                           # ══ Next.js · output: 'export' ══
│   │   ├── src/app/                   # (marketing) · (auth) · (play) · (account)
│   │   ├── src/components/            # game/ · wallet/ · rg/ · ui/
│   │   ├── src/lib/api/               # wraps packages/api-client
│   │   ├── next.config.ts             # output: 'export'
│   │   └── tests/
│   │
│   └── mobile/                        # ══ Expo SDK 57 · Android + iOS ══
│       ├── app/                       # expo-router, mirrors the web route shape
│       ├── src/components/            # platform-specific, NOT shared with web
│       ├── src/native/                # REQ-APP-003 native modules
│       │   ├── cert-pinning/
│       │   ├── root-detection/
│       │   ├── mock-location/
│       │   └── secure-storage/
│       ├── app.config.ts              # Hermes · ABI splits · OTA disabled
│       └── eas.json
│
├── packages/                          # shared, language-crossing
│   ├── engine-contract/               # ◄── the seam that makes game #3 cheap
│   │   ├── schema/                    # request and response JSON Schema
│   │   ├── fixtures/                  # (seed,input) → expected output, per game
│   │   └── README.md
│   ├── api-types/                     # TypeScript types generated from OpenAPI
│   └── api-client/                    # ◄── snake_case → camelCase boundary #2
│
├── infra/
│   ├── nginx/                         # tls · fpm · passenger · loopback engines
│   ├── systemd/                       # horizon · engine-heritage · workers
│   ├── deploy/                        # Deployer — atomic symlinked releases
│   ├── secrets/                       # SOPS-encrypted; age keys never committed
│   └── monitoring/                    # prometheus · grafana · alert rules
│
├── tools/
│   ├── phpstan/                       # custom rules: ledger writer, money typing
│   ├── gates/                         # rtp-ceiling · prohibited-rng · schema-drift
│   └── openapi/                       # spec → api-types generator
│
└── docs/                              # brownfield scan (13 files) + architecture
```

### Architectural Boundaries

| Boundary | Enforced by | What it protects |
|---|---|---|
| Wallet ↔ everything else | Database grants — only the platform's wallet user may write ledger tables | `REQ-ARCH-001`, `REQ-ARCH-002` |
| Platform ↔ engines | Loopback binding plus mTLS; engines hold no database credential at all | `REQ-GEC-002`, `REQ-SEC-011` |
| Channels ↔ platform | `/v1` HTTP only; USSD is a separate application with no database access | Structural remedy for finding B-3 |
| Identity vault ↔ platform | Separate MariaDB schema with its own credential | `REQ-ID-023` |
| Naming conventions | Exactly two mapping sites — `Http/Resources/` and `packages/api-client/` | Consistency across three languages |

### Requirements to Structure Mapping

| PRD section | Location |
|---|---|
| §6 Engine Contract | `packages/engine-contract/` + `Domain/Games/EngineClient.php` |
| §7.1 RNG and fairness | `Domain/Fairness/` |
| §7.2 Identity and KYC | `Domain/Identity/` + separate vault schema |
| §7.3 OPay collections | `Domain/Payments/Providers/Opay/` |
| §7.4 Wallet and ledger | `Domain/Wallet/` — sole writer |
| §7.5 Payout and float | `Domain/Payout/` and `Domain/Payout/Float/` |
| §7.6 Tax engine | `Domain/Tax/` with `Rulesets/` |
| §7.7 Geolocation | `Domain/Jurisdiction/` |
| §7.8 Notifications | `Domain/Notifications/` and `Jobs/` |
| §7.9 Responsible gambling | `Domain/ResponsibleGaming/` |
| §7.10 Back office | `app/Filament/` |
| §7.11 Ticket lifecycle | `Domain/Ticket/` |
| §8 BlackRed | `apps/engine-blackred/` |
| §9 Heritage | `apps/engine-heritage/` + `Domain/Games/Heritage/` |
| §10.1 Web · §10.2 App · §12 USSD | `apps/web/` · `apps/mobile/` · `apps/ussd/` |
| §11 Data model | `apps/platform/database/migrations/` |
| §17 Testing and certification | `tests/Contract/`, `tests/Invariants/`, `tools/gates/` |

### Data Flow — Ticket Creation

```text
channel → POST /v1/tickets  (Idempotency-Key)
  ├─ Jurisdiction: resolve state, check licence footprint     fail closed
  ├─ ResponsibleGaming: registry check (cached), RG limits    fail closed
  ├─ Games: registry lookup, validate stake against min/max
  ├─ Fairness: issue seed, write chained seed record
  ├─ Games/EngineClient → engine /resolve over loopback       ◄ D-08: before the transaction
  └─ BEGIN TRANSACTION
       Wallet: reserve stake from Play Balance into SUSPENSE
       persist ticket + ticketOutcome + ticketJurisdiction (write-once)
     COMMIT
  → settlement: Tax.apply → Wallet.credit(WINNINGS, net) → queue payout dispatch
```

### Development Workflow Integration

Each workspace runs independently in development — `php artisan serve` for the platform,
`uvicorn` for the Heritage engine, `next dev` for web, `expo start` for mobile — with a root
`Makefile` target bringing up the full stack against local MariaDB and Redis. The build
produces four artefacts: a Composer-installed platform release, a Python virtualenv, a static
`out/` directory for web, and EAS builds for mobile. Deployment symlinks the platform release
directory, restarts the FPM pools and the Heritage workers, and syncs the static web output to
its own Nginx root.

---

## Back Office and Admin Architecture

> Added after step 6 validation. The back office was previously represented only as
> `app/Filament/` with a one-line mapping — insufficient for a surface carrying eleven PRD
> requirements plus §4.4 and §14 of the UX Design Standard.

### Placement decision (D-18)

**The admin is a Filament panel inside `apps/platform`, served on a separate Nginx server block
with its own auth guard, IP allowlist and mandatory MFA.** Deployed as the same artefact,
firewalled as a distinct surface.

Considered and rejected: a separate `apps/admin` application. `REQ-BO-004`'s Player 360 spans
every domain, `REQ-BO-005`'s ticket audit tool calls the engine's `replay`, and maker-checker on
prize tables and manual ledger movements needs transactional access to the domain services. A
separate application would require an internal admin API mirroring nearly the entire domain
surface, doubling maintenance for no isolation benefit that a separate server block and guard
do not already provide.

### Navigation — by operator task

Per UX Design Standard §4.4, organised by what an operator is trying to do rather than by
database table. Role-based access **removes** inaccessible destinations rather than showing
disabled items.

| Panel section | Serves | Primary requirements |
|---|---|---|
| **Overview** | Exception queues first, decorative KPIs last | UX §14.4 |
| **Players** | Search, Player 360, KYC state, masked identifiers | `REQ-BO-004`, `REQ-ID-023` |
| **Tickets** | Search by reference, audit tool with engine replay, outcome trail | `REQ-BO-005`, `REQ-BO-006` |
| **Money and reconciliation** | Nightly reconciliation runs, exception queue, manual credit/debit, compensating entries | `REQ-WAL-043`, `REQ-BO-006` |
| **Payout float** | Balance, days of cover, burn rate, pending payout value, alert state, top-up recording | `REQ-BO-009`, `REQ-FLOAT-003`, `REQ-FLOAT-008` |
| **Responsible play and registry** | RG status, limit overrides, registry sync freshness, behavioural review queue | `REQ-RG-018`, `REQ-RG-020`, `REQ-BO-003` |
| **Jurisdictions and tax** | Per-state licence status and expiry, ruleset versions, tax rates, remittance status | `REQ-BO-010`, `REQ-TAX-011`, `REQ-GEO-009` |
| **Games and prize tables** | Game registry, per-channel and per-state suspension, prize table versioning and publication gate | `REQ-GEC-010`, `REQ-GEC-011`, `REQ-GEC-024`, `REQ-GEC-025` |
| **Content and Heritage catalogue** | 90-item catalogue, advisory sign-off, depiction constraints, publication gate | `REQ-HG-060`–`068` |
| **Reports and exports** | Per-state regulatory export, financial reporting by game and state | `REQ-BO-007`, `REQ-BO-008` |
| **Analytics** | Funnels, events, cohorts — the destination for §15 | `REQ-ANL-001`–`004` |
| **Audit log** | Actor, action, before/after, IP, justification; immutable | `REQ-BO-002` |
| **Users and roles** | RBAC, MFA enforcement, IP allowlists | `REQ-BO-001`, `REQ-BO-011` |

### Maker-checker as a first-class module

Previously implicit. `REQ-BO-003` lists nine change types requiring dual approval, and UX §14.3
specifies the interaction. It becomes `Domain/Approvals/`:

```
Domain/Approvals/
├── ChangeRequest.php        # polymorphic: subject type, before, after, justification
├── ApprovalWorkflow.php     # Draft → AwaitingApproval → Approved|Rejected → Applied
├── DiffRenderer.php         # before/after for the maker; risk context for the checker
├── Guards/
│   ├── SelfApprovalGuard.php    # initiator cannot approve — REQ-SEC-023
│   └── SegregationGuard.php     # no prize-table configurer may approve a payout
└── Appliers/                # per-subject application, transactional
```

Every change type routes through one workflow, so adding a tenth reviewable action is
registration rather than reimplementation. Rejection preserves the draft with a reason. Applied
changes record immutable actor, approver, version and timestamp.

### Analytics (resolves G-2)

**The admin is the analytics destination.** No third-party analytics SaaS.

This resolves more than a missing component: it removes analytics entirely from the C-11
cross-border transfer question, and satisfies `REQ-ANL-003` by construction, since the pipeline
is ours end to end and never leaves the jurisdiction the platform is hosted in.

```
Domain/Analytics/
├── EventEmitter.php         # server-side only for money and outcome events
├── Events/                  # the §15.1 catalogue, one class per event
├── Pseudonymiser.php        # playerId → stable pseudonym; blocks MSISDN/NIN/BVN/DOB/coords
├── Rollups/                 # nightly aggregation into daily fact tables
└── Funnels/                 # the six §15.2 funnels, plus UX §15 measures
```

Raw events land in an append-only `analyticsEvent` table partitioned monthly, retained 25 months
per `REQ-DATA-001`. Dashboards read pre-aggregated rollups rather than scanning raw events, so
a reporting query cannot degrade the play path. Charts carry text summaries, accessible legends
and an underlying table, per UX §14.4.

### Structure additions

```
apps/platform/app/
├── Filament/
│   ├── Panels/AdminPanelProvider.php    # separate guard, IP allowlist, MFA
│   ├── Resources/                       # one per navigation section above
│   ├── Widgets/                         # exception queues, float console, compliance console
│   └── Pages/
│       ├── PlayerThreeSixty.php         # REQ-BO-004, one task-oriented page
│       ├── TicketAudit.php              # REQ-BO-005, calls engine /replay
│       └── ReconciliationExceptions.php
└── Domain/
    ├── Approvals/                       # maker-checker workflow (above)
    ├── Analytics/                       # G-2 (above)
    └── Reporting/                       # G-5 (below)
```

### Gap resolutions

**G-3 — Draw Partner Bridge, now modelled.** `REQ-HG-030`–`042` require an adapter interface
with per-partner implementations, a configurable calendar handling multiple draws per day,
durable retry, circuit breaker and daily reconciliation.

```
Domain/Games/Heritage/DrawPartner/
├── DrawPartnerClient.php    # submitEntry · getReceipt · getDrawCalendar · getResults · reconcile
├── Adapters/                # one per contracted partner (C-07)
├── DrawCalendar.php         # configuration, multiple draws per day, same-day shifts
├── EntryRouter.php          # per-entry routing across partners — REQ-HG-042
├── CircuitBreaker.php       # REQ-HG-040
└── Jobs/SubmitEntry.php     # async, durable, retried; never blocks settlement
```

**G-4 — Heritage assets, placeholders only.** Per instruction, the asset pipeline is deferred.
Wired now so nothing blocks on it: `catalogueItem.assetRef` points into
`apps/platform/storage/app/heritage/catalogue/`, which holds a `manifest.json` of the 90 item
numbers and placeholder artwork. The immutability constraint (`REQ-HG-060`, `REQ-HG-061`) is
enforced at the data layer immediately — the mapping is fixed even while the art is not, which
is the ordering that matters, since renumbering after the first live ticket is prohibited.

**G-5 — Regulatory export, adopted as recommended.**

```
Domain/Reporting/
├── StateExtract.php         # filtered to one state's attributed activity, arbitrary range
├── Formats/                 # CSV and machine-readable (JSON), per REQ-BO-008
├── FinancialReport.php      # by game and state: stakes, payouts, GGR, RTP actual vs modelled,
│                            #   provider fees, tax lines, float movements — REQ-BO-007
└── RemittanceReconciliation.php  # attributed activity vs remittances — REQ-GEO-009, REQ-TAX-008
```

Exports run as queued jobs writing to signed, expiring download links rather than streaming
synchronously, so a wide date range cannot occupy a web worker. Every export is audit-logged
with actor, filter and row count.

**G-6 — Warm standby, added.**

```
infra/standby/
├── replication/             # MariaDB async replica + binlog shipping — REQ-HOST-009
├── failover-runbook.md      # named owner, deputy, out-of-hours escalation
└── egress-ip.md             # static IP reprovisioning at the standby
```

The critical detail: `REQ-PAY-011` means OPay whitelists the egress IP in both directions, so
**a standby that comes up on a different IP cannot process payments.** Either the static IP is
reprovisionable at the standby, or both IPs are registered with OPay in advance. Registering
both in advance is the recommendation — it costs nothing and removes a failover step from the
critical path. Restore drills are exercised monthly against the replica (`REQ-NFR-027`).

**G-7 — Filament recorded as decision D-18**, above.

---

## Architecture Validation Results

### Coherence Validation

**Decision compatibility.** Technology choices compose without conflict: Laravel 13 and FastAPI
communicate only over the Engine Contract and share no runtime; Next.js static export and Expo
consume one `/v1` API through a single generated client; Redis serves sessions, queues, USSD
state and caches without cross-purpose contention. No decision contradicts another.

**No outstanding deviations.** D-08's reordering of the ticket pipeline has been folded into the
specification: `REQ-TKT-002` now defines an eligibility-and-resolution phase followed by a
commitment transaction, with `REQ-TKT-011` and `REQ-TKT-012` covering engine idempotency,
discarded outcomes and in-transaction re-assertion of limits.

**Pattern consistency.** The two-boundary naming rule holds because exactly two client surfaces
exist. The ledger-writer restriction is enforceable because engines carry no database
credential. Fail-closed handling is expressible because geo and registry are discrete services
rather than inline checks.

**Structure alignment.** Every architectural boundary maps to a deployment or credential
boundary rather than to a folder convention, which is what makes the boundaries survive contact
with a delivery team.

### Requirements Coverage Validation

All 27 requirement modules now map to a location. `REQ-ANL` resolves to `Domain/Analytics/`
with the admin as destination; `REQ-HG`'s draw-partner clause resolves to
`Domain/Games/Heritage/DrawPartner/`; `REQ-COMP`'s reporting clauses resolve to
`Domain/Reporting/`; `REQ-BO`'s eleven requirements resolve to the Filament panel and
`Domain/Approvals/`.

**Non-functional coverage** is complete except for throughput — see G-1, which is a PRD-internal
tension rather than an architectural gap.

### Implementation Readiness Validation

**Decision completeness.** Eighteen decisions recorded with rationale. Two version facts remain
unverified (Laravel 13's minimum PHP version, the current Next.js major) because web search hit
its session limit; both are pre-adoption checks rather than blockers.

**Structure completeness.** The tree is concrete rather than illustrative, with the four
post-validation additions folded in.

**Pattern completeness.** Nineteen conflict points addressed across naming, money, time,
structure, format, communication and process, each with a worked example.

### Gap Analysis Results

| ID | Gap | Status |
|---|---|---|
| **G-1** | Throughput target versus the single-server launch topology | Resolved — see below |
| G-2 | No analytics component | Resolved — `Domain/Analytics/`, admin as destination |
| G-3 | Draw Partner Bridge absent from structure | Resolved — `Domain/Games/Heritage/DrawPartner/` |
| G-4 | No Heritage asset pipeline | Deferred by instruction — placeholders wired, immutable mapping enforced now |
| G-5 | Per-state regulatory export not modelled | Resolved — `Domain/Reporting/` |
| G-6 | No warm standby | Resolved — `infra/standby/`, with the egress-IP constraint identified |
| G-7 | Back office under-specified; Filament undecided | Resolved — D-18 and the Back Office section |

**G-1 — resolved.** The concern as first raised cited a 1,000 tickets/s sustained target, which
was drawn from the superseded `Buzzycash_Platform_PRD.md` rather than from the current
specification. `Betplus_PRD.md` §13.1 already tiers throughput against topology:
**300 tickets/s sustained and 800 tickets/s burst on the launch topology**, with 1,500
tickets/s on the scaled topology, and `REQ-NFR-015` requiring load tests against the actual
cPanel configuration rather than a container approximation. At 300 tickets/s the arithmetic is
demanding but defensible on a tuned single server, particularly since the wallet `FOR UPDATE`
locks are per-player rather than global.

Two additions close the residual risk:

- **`REQ-NFR-016` — a scale-out trigger.** Capacity tiers without a trigger are a familiar
  failure: the numbers are agreed, growth is gradual, and the migration happens under load
  rather than ahead of it. Sustained peak above 60% of load-tested launch capacity over a
  rolling 7-day window now fires the `REQ-HOST-008` scale-out, monitored and alerted like a
  float threshold rather than left to discretion.
- **`REQ-NFR-017` — explicit per-service resource limits.** One box runs Nginx, three PHP-FPM
  pools, Python workers, MariaDB, Redis, queue workers and monitoring. Reporting queries,
  export jobs and analytics rollups are capped and de-prioritised so they cannot starve the
  play path — which matters more now that analytics is first-party and runs on the same host.

### Architecture Completeness Checklist

**Requirements Analysis**

- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed
- [x] Technical constraints identified
- [x] Cross-cutting concerns mapped

**Architectural Decisions**

- [ ] Critical decisions documented with versions — two version facts unverified
- [x] Technology stack fully specified
- [x] Integration patterns defined
- [x] Performance considerations addressed — G-1 resolved via `REQ-NFR-016`/`017`

**Implementation Patterns**

- [x] Naming conventions established
- [x] Structure patterns defined
- [x] Communication patterns specified
- [x] Process patterns documented

**Project Structure**

- [x] Complete directory structure defined
- [x] Component boundaries established
- [x] Integration points mapped
- [x] Requirements to structure mapping complete

**15 of 16 confirmed.**

### Architecture Readiness Assessment

**Overall Status: READY WITH MINOR GAPS**

**Confidence Level: high.** The money path, the engine boundary and the channel boundaries —
where this system's risk actually lives — are fully specified and enforced structurally rather
than by convention. The single unchecked item is a pair of version lookups blocked by a search
rate limit, which does not block starting work.

**Key strengths.** The ledger-writer restriction and engine purity are enforced by credentials
and deployment topology, not by review discipline. The shared Engine Contract fixture set makes
the "third game is an adapter plus configuration" claim testable rather than aspirational. The
three inherited defects that mattered most — the revenue-governed engine, the duplicated USSD
engine, and migrations that cannot build a database — each have a structural remedy. Maker-checker
is one workflow rather than nine bespoke implementations. Analytics staying in-house removes a
compliance dependency instead of creating one.

**Areas for future enhancement.** Read replicas once reporting volume justifies them; a second
payment provider behind the existing Payment Orchestrator seam; multi-partner draw routing;
the Heritage asset pipeline when the catalogue is commissioned.

### Implementation Handoff

**AI agent guidelines.** Read `project-context.md` before writing code — its 27 rules override
style preference. Follow the recorded decisions exactly; where a decision deviates from the PRD
it says so and why. Respect the four enforced boundaries. Map naming conventions only at the two
designated sites.

**First implementation priority.** Reconstruct the missing DDL into Laravel migrations and stand
up staging. Nothing in §17's release gates can run until a database can be built from source
control, which makes it the true critical path — ahead of any feature work.
