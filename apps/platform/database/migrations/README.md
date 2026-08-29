# Migrations

**These migrations are the sole authority for the database schema. Hand-run SQL is prohibited.**

## Why this rule exists

The system this project replaces had five migrations — 003 through 007 — that were never
committed. The only authoritative copy of its schema was the running production database. The
consequences were not theoretical:

- No staging environment could be built, because nothing could reproduce the schema.
- No automated test could run against a real database.
- Three tables the application reads and writes every day (`gameRound`, `dailyRevenueSummary`,
  `eventLog`) had no `CREATE` statement anywhere in source control.
- Reconstructing them required inferring column types from `INSERT` statements and
  cross-referencing a SQLite test double someone had hand-written years earlier.

Every release gate in PRD §17 depends on being able to build a database from source. That is why
this is a rule and not a preference.

## The gate

`tools/gates/schema-drift.php` builds a reference schema from these migrations alone, in a
throwaway database, fingerprints every table, column, type, nullability and index, and compares
that against the live schema.

```bash
php tools/gates/schema-drift.php           # compare live schema to a fresh migrate
php tools/gates/schema-drift.php --print   # print the reference fingerprint only
```

Exit codes: `0` no drift · `1` drift detected · `2` the comparison could not run.

It runs in CI from Story 1.5. **If it goes red, write the migration — do not fix the database.**

## Conventions

| | |
|---|---|
| Table names | camelCase, singular — `player`, `playerSession`, `auditLog` |
| Column names | camelCase — `registeredName`, `kycTier`, `createdAt` |
| Enumerations | `VARCHAR` + an application-level enum, **never** MySQL `ENUM` |
| Money | Integer minor unit, column named for it — `*Kobo`. Never a float, never a decimal |
| Timestamps | `DATETIME` in UTC. Business dates are computed in WAT — see `project-context.md` |
| Engine | InnoDB, `utf8mb4_unicode_ci` |

### On `VARCHAR` instead of `ENUM`

A deliberate departure from the schema this replaces, which used `ENUM` extensively. Ticket
states, game statuses, tier codes and account types all evolve, and every `ENUM` change is an
`ALTER TABLE` — against a partitioned money table, under load, on a single application server.
The constraint moves into application code where it can be changed without a migration.

## Scope

Tables arrive with the story that needs them, not upfront:

| Epic | Tables |
|---|---|
| **1** (here) | `player`, `signupSession`, `authtoken`, `rateLimitBucket`, `nameLookupCache`, `kycRecord`, `playerSession`, `passwordResetRequest`, `systemConfig`, `auditLog`, `smsLog` |
| 2 | `account`, `wallet`, `walletTransaction`, `ledgerEntry`, `depositTurnover`, `depositRequest` |
| 3 | `game`, `prizeTable`, `prizeTier`, `ticket`, `ticketOutcome`, `ticketJurisdiction`, `taxDeduction`, `rngSeedRecord` |
| 4 | `payoutOrder`, `withdrawalRequest`, `floatSnapshot`, `floatTopUp` |
| 5 | `registryStatus`, `rgRestriction` |
| 6 | `institutionUser`, `institutionRole`, `changeRequest`, `analyticsEvent` |
| 7 | `catalogueItem`, `heritageReveal`, `secondChanceEntry` |

`identityVault` is not in this list. NIN and BVN live in a **separate schema with its own
credential**, so application services cannot reach them even if compromised (`REQ-ID-023`). That
is Story 1.10 and it is provisioned separately.

## Deferred foreign keys

`kycRecord.verifiedBy` and `systemConfig.lastChangedBy` both reference `institutionUser`, which
arrives with the back office in Epic 6. The columns exist and are nullable; the constraints are
added then. This is noted in each migration rather than left to be discovered.

## Partitioning

`ledgerEntry`, `ticket` and `taxDeduction` need monthly range partitions, and `ledgerEntry` an
index on `stateCode` for per-state remittance queries (`REQ-NFR-032`). These are defined **at
creation** in Stories 2.1 and 3.6. Partitions are free to define on an empty table and expensive
to retrofit onto a populated one.
