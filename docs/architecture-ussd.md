# Architecture — USSD (`ussd`)

**Root:** `BlackRed/EngineAndServices/ussd/`
**Type:** backend · **Runtime:** PHP 8.1+ under Apache · **Pattern:** procedural finite state machine

---

## 1. Executive summary

A self-contained USSD application sharing the platform's database but **none of its code**. No
namespace, no autoloader, no Composer entry, no strict types, its own config file, its own PDO
wrapper, its own migrations directory. It re-implements registration, deposits, withdrawals and
the entire game engine.

The FSM design itself is good. The duplication is the problem.

## 2. The state machine

`lib/states.php` declares `USSD_STATE_FILES` as the stated source of truth — 23 states, each a
file in `states/` exporting a `render_<NAME>()` / `handle_<NAME>()` function pair, both taking
`$session` by reference. `index.php` dispatches a turn, the state mutates the session, and the
dispatcher commits it at end of turn.

```
ENTRY  ──▶ UNREGISTERED_MENU ──▶ REG_PICK_PROVIDER ──▶ REG_CONFIRM_NAME
   │                                   ──▶ REG_SET_PASSWORD ──▶ REG_CONFIRM_PASSWORD
   └──▶ MAIN_MENU
          ├── BALANCE
          ├── LAST_STAKE
          ├── PLAY_PICK_TYPE ─▶ PLAY_PICK_COLORS ─▶ PLAY_ENTER_STAKE ─▶ PLAY_CONFIRM ─▶ PLAY_AGAIN
          ├── DEP_ENTER_AMOUNT ─▶ DEP_CONFIRM
          └── WD_PICK_DESTINATION
                 ├── WD_PLAY_ENTER_AMOUNT ─▶ WD_PLAY_CONFIRM ─▶ WD_PLAY_PASSWORD
                 └── WD_MOMO_ENTER_AMOUNT ─▶ WD_MOMO_CONFIRM ─▶ WD_MOMO_PASSWORD
```

Session state persists in a `ussdSession` table keyed by MSISDN and session id, with
`workers/cleanup.php` reaping expired rows on cron.

This maps almost directly onto PRD §12.2 (`REQ-USSD-001`: explicit FSM, session in Redis
mirrored to `ussdSession`, `SELECT … FOR UPDATE` per turn). **The pattern is the asset here** —
the target needs the same shape with Redis in front and both games behind it.

## 3. Code inventory

| File | LOC | Duplicates |
|---|---|---|
| `lib/game.php` | 545 | `src/Wallet/GameEngineService.php` |
| `lib/withdrawal.php` | — | `src/Wallet/WithdrawalService.php` |
| `lib/deposit.php` | — | `src/Wallet/DepositService.php` |
| `lib/player.php` | — | `src/Auth/SignupService.php` |
| `lib/anm.php` | — | `src/Integrations/AnmClient.php` |
| `lib/hubtel.php` | — | `src/Integrations/HubtelSmsClient.php` |
| `lib/password.php` | — | `src/Auth/PasswordHasher.php` |
| `lib/db.php` | — | `src/Database/Connection.php` |
| `lib/msisdn.php` | — | `src/Support/GhanaPhone.php` |
| `lib/session.php`, `lib/states.php`, `lib/log.php`, `lib/response.php` | — | USSD-specific, no equivalent |

Nine of thirteen library files are re-implementations of code that already exists in `src/`.

## 4. The duplicate engine — finding B-3

`lib/game.php` independently re-implements:

- `const GAME_MULTIPLIERS = [1=>2, 2=>10, 3=>20, 4=>50, 5=>100]` — the same table, declared twice.
- The `fair_random` / `forced_loss` decision, including the identical `netRevenueToday < 0`
  house-bleeding guard, in `gamePlay()` at lines ~300–315.
- The cryptographic Fisher-Yates shuffle and the forced-loss card construction.
- Direct `INSERT` into `walletTransaction` and `ledgerEntry`, and `UPDATE` of
  `dailyRevenueSummary` and `wallet` — **writing the ledger from a second codebase**.

Both implementations mutate the same `dailyRevenueSummary` row, so a web player's outcome is
influenced by USSD play and vice versa, through two code paths that must stay in lockstep by
hand.

Any divergence between the two files is a silent fairness defect: two players staking the same
amount on the same game on different channels can face different odds, with nothing in the
system detecting it. PRD `REQ-HG-005` and `REQ-QA-010` exist precisely to make this impossible.

## 5. USSD implementation details worth preserving

- **No word input.** Every prompt is numeric or a short colour code, matching PRD §12.1.
- **Name confirmation, not entry** — registration confirms the mobile-money account name rather
  than asking the player to type it (`REQ-ID-011`).
- **Password set over USSD** with confirm step, then reused for withdrawal authorisation.
- **Two withdrawal destinations** (play balance vs mobile money) modelled as distinct state
  branches — the dual-balance concept is already present in the channel.
- **Session resume** and cron-based cleanup.

## 6. Gaps against PRD §12

| Requirement | Current |
|---|---|
| `REQ-USSD-001` session in Redis, mirrored to DB | DB only |
| `REQ-USSD-003` funded ticket never lost to a dropped session | No auto-settle path |
| `REQ-QA-013` every screen ≤160 chars, build fails on overflow | No length test |
| `REQ-USSD-010` in-session funding via provider OTP | ANM flow, to be replaced by OPay Bank Account + OTP |
| `REQ-USSD-020` limits and break within two screens of main menu | No responsible-gambling menu exists |
| `REQ-NOT-008` USSD outcome always mirrored to SMS | Partial — Hubtel used, not systematically |
| Gateway signature validation (`REQ-ID-004`, `REQ-SEC-009`) | Not evident in `index.php` — **verify before reuse** |

## 7. Target disposition

The USSD part should become a **channel adapter**: an FSM that renders screens and calls the
platform API, holding no money logic and writing no ledger entries. The state machine, screen
copy and flow structure port across; `lib/game.php`, `lib/deposit.php`, `lib/withdrawal.php`
and `lib/player.php` are deleted rather than migrated.
