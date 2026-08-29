# Development Guide

How the current system is set up and run, and what is missing before it can meet the PRD's
release gates.

---

## 1. Prerequisites

- PHP **8.1+** with `pdo`, `pdo_mysql`, `json`, `openssl`, `mbstring`, `curl`
- MySQL or MariaDB
- Apache with `mod_rewrite` (`.htaccess` is used in six locations)
- Composer

No Node, no Redis, no Python, no Docker.

## 2. Setup

```bash
cd BlackRed/EngineAndServices
composer install          # also copies .env.example → .env if absent
# configure .env
mysql -u <user> -p <db> < migrations/001_initial_schema.sql
mysql -u <user> -p <db> < migrations/002_phase2_auth.sql
mysql -u <user> -p <db> < migrations/test_only_authtoken.sql
mysql -u <user> -p <db> < ussd/migrations/008_ussd_channel.sql
mysql -u <user> -p <db> < ussd/migrations/009_drop_source_columns.sql
cp ussd/config.example.php ussd/config.php   # configure separately
```

**This does not produce a working system.** `gameRound`, `dailyRevenueSummary`, `eventLog` and
`vw_walletDualBalance` have no migration — see [data-models.md](./data-models.md) §2.1. The
first play request against a freshly provisioned database will fail.

**Reconstructing the missing DDL from the production database is the first practical task of
any refactor**, and it is a prerequisite for staging (`REQ-HOST-010`) and for every automated
test the PRD requires.

## 3. Configuration

Two independent config systems, each with its own file:

| Scope | Mechanism | File |
|---|---|---|
| `platform-api` | `vlucas/phpdotenv` → `Bootstrap\Config` | `.env` (56 keys) |
| `ussd` | plain PHP array | `ussd/config.php` |
| Runtime, both | `systemConfig` table → `SystemConfigService` | database |

Key groups in `.env`: `APP_*`, `DB_*`, `LOG_*`, `SESSION_*`, `RATE_LIMIT_*` (9 buckets),
`PASSWORD_*` (Argon2 cost), `ANM_*` (7), `HUBTEL_*` (5), `DEPOSIT_*`, `WITHDRAWAL_*`,
`PLAY_BALANCE_PERCENT_MAX`, `GAME_MIN_STAKE_PESEWAS`.

Runtime keys in `systemConfig`: `engine.gameEngineEnabled`, `engine.houseWinThresholdPct`,
`engine.dailyRevenueFloorPesewas`, `game.maxStakePesewas`, `game.dailyStakeLimitPesewas`.

## 4. Finding B-5 — committed secrets

`.env` is present in the working tree with live values for `DB_PASS`, `APP_SECRET`,
`ANM_CLIENT_KEY`, `ANM_SECRET_KEY`, `ANM_CALLBACK_SHARED_SECRET`, `HUBTEL_SMS_CLIENT_ID` and
`HUBTEL_SMS_CLIENT_SECRET`. `ussd/config.php` carries a second copy of the same class of
credential.

There is **no `.gitignore` at the project root** — only `ussd/.gitignore`. The repository is
not currently under version control, which means these have not been committed to a history
yet. That is the one piece of good news, and it makes this cheap to fix **now** and expensive
to fix later.

Actions, in order:
1. Rotate every credential above — they must be assumed compromised.
2. Add a root `.gitignore` covering `.env`, `ussd/config.php`, `vendor/`, `logs/`, `*.zip`,
   `error_log`, `.DS_Store`, `__MACOSX/`.
3. Initialise version control **only after** 1 and 2.
4. Move secrets to a managed store before launch (`REQ-SEC-005`).

## 5. Running

| Part | How |
|---|---|
| API | Apache document root → `public/`, front controller `public/index.php` |
| USSD | Gateway posts to `ussd/index.php` |
| Session cleanup | `ussd/workers/cleanup.php` on cron |

No local dev server script, no `docker-compose`, no seed data, no fixtures.

## 6. Testing

`composer test` → `php tests/run.php`. **That file does not exist.** The only working runner is
`ussd/tests/run.php`.

There is no unit test suite, no coverage measurement, no static analysis, no linter config and
no CI. Against PRD §17 this means every one of the following has to be built from nothing:

≥85% coverage on engine/wallet/ledger/tax/payout · Engine Contract conformance suite ·
Monte Carlo RTP validation over 10M tickets · determinism and replay tests · engine purity
static analysis · prohibited-randomness build failure · outcome-leak tests · ledger invariants
under failure injection · idempotency under concurrency · double-payout replay · USSD screen
length · provider unit and signature tests · fail-closed tests · tax matrix · turnover.

**Recommended baseline before refactoring begins** — characterisation tests that pin current
behaviour, so the refactor has a safety net:

1. A test database provisioned from reconstructed DDL.
2. Golden-file tests over `GameEngineService::play()` covering both engine paths.
3. Ledger-balance assertions after each money operation.
4. A parity harness running the same inputs through `src/Wallet/GameEngineService.php` and
   `ussd/lib/game.php` and asserting identical outcomes — this will either prove the two engines
   agree or find where they have already drifted.

## 7. Code standards observed

`src/` is consistent: `declare(strict_types=1)`, PSR-4, PSR-12-ish formatting, constructor
injection, typed properties and returns, prepared statements only, no debug statements, useful
docblocks that explain *why*. It is better than its structure suggests.

`ussd/` follows none of this: no strict types, no namespace, no autoloader, global functions,
`$session` mutated by reference.

Neither has a formatter config, a PHPStan/Psalm baseline or a lint step.

## 8. Highest-value fixes independent of the refactor

These improve the running production system and are worth doing whether or not the target
architecture proceeds:

1. Rotate the exposed credentials (§4).
2. Reconstruct the missing DDL into a real migration (§2).
3. Confirm callback and USSD gateway signature verification —
   [integration-architecture.md](./integration-architecture.md) §4.
4. Put the repository under version control with a correct `.gitignore`.
5. Build the two-engine parity harness (§6.4).
