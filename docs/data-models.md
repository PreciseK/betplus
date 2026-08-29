# Data Models

**Engine:** MySQL / MariaDB, InnoDB · **Access:** PDO prepared statements, no ORM
**Migrations:** three separate locations, none authoritative

---

## 1. Migration inventory

| File | Creates |
|---|---|
| `migrations/001_initial_schema.sql` (1142 LOC) | 26 tables — identity, ledger, payments, games, ops |
| `migrations/002_phase2_auth.sql` (177 LOC) | `rateLimitBucket`, `csrfToken`, `signupSession`. **`authtoken` is present only as a commented-out block.** |
| `migrations/test_only_authtoken.sql` (23 LOC) | `authtoken` — the only live definition, in a file named "test only" |
| `ussd/migrations/008_ussd_channel.sql` | `ussdSession` |
| `ussd/migrations/009_drop_source_columns.sql` | Drops `source` from `depositRequest`, `withdrawalRequest` |

## 2. Finding B-4 — the migrations do not build a working database

### 2.1 Five entire migrations are missing

> **Corrected 11 August 2026.** The original scan reported four missing objects. The gap is
> larger and its cause is now identified.

`ussd/migrations/008_ussd_channel.sql` states in its header: *"Run order: after the web app's
migrations 001-007."*

**Migrations 003, 004, 005, 006 and 007 do not exist in this repository.** Only `001` and `002`
are present. The missing objects below were almost certainly created in those five files, which
means the gap is not a handful of forgotten tables — it is a contiguous block of schema history
that never reached source control.

| Object | Used by | Consequence |
|---|---|---|
| `gameRound` | `GameEngineService:305`, `StakesService`, `ussd/lib/game.php:510` | Every play writes an audit row to a table that cannot be provisioned |
| `dailyRevenueSummary` | `GameEngineService:540`, `ussd/lib/game.php:484` | The engine's decision input does not exist in a fresh database |
| `eventLog` | `ForgotPasswordService:186`, `PasswordChangeService:110` | Written by two services; **read by nothing** |

`authtoken` is functionally in the same category: its only executable definition lives in a file
explicitly labelled test-only.

**Correction:** `vw_walletDualBalance` is **not** missing. It is defined at
`migrations/001_initial_schema.sql:1079` and matches its only consumer (`MeController`)
column-for-column. The original scan was wrong on this point.

### 2.1.1 A partial schema record does exist

`ussd/tests/run.php` contains a SQLite test-double schema covering twelve tables — including
`gameRound` and `dailyRevenueSummary` — whose author states it was verified against a live
`SHOW COLUMNS`. It is not authoritative and its types are SQLite rather than MariaDB, but it is
the closest thing to a record of the missing migrations and is the best available cross-check
for a reconstruction.

Reconstructed candidate DDL, evidenced line by line against application code and this test
double, is at [`reconstructed-ddl.sql`](./reconstructed-ddl.sql). It must still be validated
against the production database before use.

**The schema's real source of truth is the running production database.** A staging environment
that mirrors production (`REQ-HOST-010`) cannot currently be built from this repository, and
neither can a test database for the PRD's release gates.

### 2.2 Tables defined but never referenced by any code

`kycRecord`, `institutionRole`, `institutionUser`, `gameEvent`, `gamePlay`, `drawResult`,
`gamePayout`, `suspiciousActivityFlag`, `auditLog`, `winAnalysisDaily`, `revenueDaily`,
`marketingEvent`, `playerEventRegistration`.

Thirteen of twenty-six tables in the initial schema are dead. Several are aspirational designs
for capabilities the PRD also requires — `auditLog` (`REQ-BO-002`), `suspiciousActivityFlag`
(`REQ-SEC-022`), `institutionUser`/`institutionRole` (`REQ-BO-001`), `kycRecord`
(`REQ-ID-020`). **The schema anticipated features the code never implemented**, which is worth
knowing before treating any of them as "already built".

Note that `gamePlay` matches a *function* name in `ussd/lib/game.php`, not a table reference;
the table itself is unused.

## 3. Live core — the money model

This is the part of the schema that matters most, and the part closest to the PRD target.

```
account            accountCode, accountType ENUM, ownerType ENUM(player|house|momo|system),
                   normalBalance ENUM(debit|credit), status
                   └─ codes in use: PLAYER_PLAY:{id}, PLAYER_PAYOUT:{id}, HOUSE_REVENUE

wallet             playerId, walletType ENUM(PLAY|PAYOUT), accountId,
                   cachedBalancePesewas, version, status
                   └─ UNIQUE(playerId, walletType)   ← one wallet per type per player

walletTransaction  refNumber, txnType ENUM, playerId, amountPesewas, currency,
                   status ENUM(pending|completed|failed|reversed),
                   metadata JSON, initiatedBy, channel ENUM(web|app|ussd|system|admin)

ledgerEntry        walletTxnId, accountId, side ENUM(debit|credit),
                   amountPesewas, currency, description
```

**Correct:** balanced double entry, optimistic concurrency via `version`, `FOR UPDATE` on
wallet reads, integer minor units, no floating point anywhere.

**Against the PRD:**

| PRD requirement | Gap |
|---|---|
| `REQ-WAL-003` integer kobo | Integer **pesewas**; `GHS` hard-coded in SQL literals |
| `REQ-WAL-010` `PLAYER_WINNINGS` naming | `PLAYER_PAYOUT` / `walletType='PAYOUT'` |
| `REQ-WAL-012` state-partitioned tax accounts | No `WHT_PAYABLE:{state}`, no `OPAY_FLOAT`, no `SUSPENSE`, no `PRIZE_LIABILITY`, no `FEES` |
| `REQ-WAL-020` stake reserved into `SUSPENSE` | Stake goes **straight to `HOUSE_REVENUE`**; no suspense stage |
| `REQ-WAL-040` append-only journal enforced by permissions and triggers | Not enforced — ordinary table, app-level convention only |
| `REQ-WAL-041` negative balances impossible at DB level | Not enforced by constraint |
| `REQ-WAL-030` 1× turnover, `depositTurnover` table | Absent. `.env` carries `PLAY_BALANCE_PERCENT_MAX` — the proportional-cap model the PRD rejects |
| `REQ-GEO-001` `ledgerEntry.stateCode` | Absent |
| Idempotency on the play path | `refNumber` is generated server-side per round, not supplied by the caller |

## 4. Currency and locale coupling — finding B-7

The Ghana market is compiled into the schema and the code, not configured:

- `*Pesewas` appears in ~40 column names — `amountPesewas`, `cachedBalancePesewas`,
  `stakePesewas`, `payoutPesewas`, `potentialPayoutPesewas`, `floorPesewas`, `winsPesewas`,
  `lossesPesewas`, `netRevenuePesewas`, `winsTodayPesewas`, `minPesewas`/`maxPesewas` config keys.
- `'GHS'` is a string literal inside `INSERT` statements, not a column default.
- `kycRecord.idType ENUM('ghana_card','passport','voter_id','drivers_license')`.
- `Africa/Accra` as a class constant in both engines.

Renaming touches schema, every SQL string, both engines, the USSD library and the config keys.
This is a mechanical but wide migration and should be scheduled as its own unit of work rather
than folded into feature phases.

## 5. Identity and session

| Table | Notes |
|---|---|
| `player` | MSISDN-keyed; `kycStatus`, `accountStatus`, `registrationChannel ENUM(web,app,ussd)` |
| `playerSession` | DB-backed sessions — blocks stateless horizontal scaling |
| `authtoken` | OTP / reset tokens; definition only in the test-only file |
| `signupSession` | Three-step wizard state |
| `rateLimitBucket` | DB-backed rate limiting — hot-path writes on every request |
| `csrfToken` | Defined; usage not evident in `src/` |
| `ussdSession` | FSM state per `(msisdn, sessionId)` |
| `passwordResetRequest` | |
| `nameLookupCache` | Caches mobile-money name lookups — carries over to OPay wallet validation |

Sessions, rate limits and USSD state are all database rows. The PRD assumes Redis for all three
(`REQ-HOST-001`, `REQ-ID-026`, `REQ-USSD-001`); this is a meaningful change to the hot path.

## 6. Operational and audit

`momoApiCallLog` — every provider request/response, immutable. This is exactly the
`opayApiCallLog` shape the PRD requires (`REQ-NFR-045`) and should be carried across.

`smsLog` — delivery records. Carries across.

`auditLog`, `suspiciousActivityFlag` — defined, never written. The PRD requires both.

## 7. Target schema delta

Beyond renames, the target adds — none of which exist in any form today:

`game`, `prizeTable`, `prizeTier`, `ticket`, `ticketOutcome`, `ticketJurisdiction`,
`taxDeduction`, `jurisdictionRuleset`, `registryStatus`, `rgRestriction`, `depositTurnover`,
`payoutOrder`, `floatSnapshot`, `floatTopUp`, `identityVault`, `rngSeedRecord`,
`catalogueItem`, `heritageReveal`, `secondChanceEntry`, `blackredDraw`.

And retires: `gameEvent`, `gamePlay`, `drawResult`, `gamePayout`, `dailyRevenueSummary`
(the engine governor), `winAnalysisDaily`, `revenueDaily`, `marketingEvent`,
`playerEventRegistration`.
