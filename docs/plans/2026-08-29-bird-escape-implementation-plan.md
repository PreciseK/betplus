# Bird Escape Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (or superpowers:subagent-driven-development) to implement this plan task-by-task. This document is phase-level strategy; expand any phase into bite-sized RED/GREEN/commit tasks before executing it.

**Goal:** Ship Bird Escape — a live, multiplayer crash-style betting game — integrated into Buzzycash's existing wallet/ledger, fairness, and game-registry systems, with a new WebSocket layer for real-time multiplier broadcast.

**Architecture:** A new `BIRDESCAPE` domain (`app/Domain/Games/BirdEscape`) owns the round lifecycle as a single server-authoritative long-running process (an artisan command), which persists live round state to Redis and broadcasts ticks over Laravel Reverb. Bet placement and cash-out reuse `WalletService::reserveStake/settleWin/settleLoss` exactly as `CreateTicket` does today. The crash point uses a new commit-reveal HMAC-SHA256 engine, following the same tamper-evident hash-chain idea as the existing `Fairness\SeedIssuer`, but round-scoped (hash published before the round, seed revealed after). Frontend follows the established `components/games/<Game>GameFlow` convention already used by BlackRed.

**Tech Stack:** Laravel Reverb (new dependency) for WebSocket broadcast + Laravel Echo/pusher-js on the client; existing Redis (already wired, see `.env.example`) for live round state; existing Ledger/Wallet/Fairness/ResponsibleGaming domains; new `birdEscapeRounds` / `birdEscapeBets` tables; Next.js (`apps/web`) for the frontend.

**Reuse map (rung 2 of the ladder — nothing below is greenfield):**

| Need | Existing thing to reuse | Where |
|---|---|---|
| Stake debit / win credit / loss settlement | `WalletService::reserveStake/settleWin/settleLoss` | `app/Domain/Wallet/WalletService.php` |
| CSPRNG + tamper-evident hash chaining | `Fairness\SeedIssuer` pattern (adapt, don't fork) | `app/Domain/Fairness/SeedIssuer.php` |
| Game catalog / min-max stake / active flag | `GameRegistry` model | `database/migrations/2026_08_17_000200_create_game_registry_table.php` |
| Idempotent writes | `idempotent` route middleware, `idempotencyKey` column pattern | `routes/v1.php:40`, `Ticket` migration |
| Player auth on HTTP + must extend to WS auth | `EnsureAccessToken` (cache-backed bearer/cookie token) | `app/Http/Middleware/EnsureAccessToken.php` |
| RG/KYC/exclusion/velocity gates | `LimitsService`, `ProtectionService`, `RegistryCheckService`, `VelocityService` | same constructor pattern as `CreateTicket` |
| Frontend game module shape | `components/games/BlackRedGameFlow/*`, `ConnectedBlackRedGameFlow.tsx`, `mocks/blackred.ts` | `apps/web/src/components/games/` |
| Deploy pattern for a long-running/supervised process | systemd unit precedent from the deferred Horizon queue work | `infra/systemd/` |

**Open decisions needing your sign-off before Phase 4 starts** (mirrors the spec's own "Open items for engineering," resolved with a default proposal below — flag if you want a different call):

1. **Crash tie-break** (close-gate arrives same tick as crash): proposed default — crash wins. The round loop, on transitioning to CRASHED, takes a row lock on the round; any close-gate request that acquires the lock after that point is rejected as too late. Server clock is always authoritative, per spec.
2. **Auto-close for disconnected players**: proposed default — resolved for free by server-side auto-close (Phase 6), since the server evaluates every bet's auto-close target on every tick regardless of client connectivity.
3. **Min/max bet per slot**: proposed default — reuse `GameRegistry.minStakeKobo/maxStakeKobo`, one row per slot count (no schema change needed).
4. **Crash-point formula house edge constant**: a standard, widely-used formula is proposed in Phase 3 (same family as Bustabit/Aviator clones) with a placeholder edge constant — **this still needs your compliance/gambling-math review before launch**, exactly as the spec says.

---

## Phase 1 — Realtime infrastructure (Laravel Reverb)

**Why first:** every later phase depends on being able to broadcast, so proving this works end-to-end (one event, one subscriber) de-risks the rest.

**Tasks:**
1. `composer require laravel/reverb` in `apps/platform`; run `artisan reverb:install` to scaffold `config/broadcasting.php`, `routes/channels.php`, and `BROADCAST_CONNECTION=reverb` in `.env.example` (document, don't set secrets).
2. Extend `EnsureAccessToken`'s cache-token lookup into a broadcasting auth callback in `routes/channels.php`: a private channel `bird-escape.player.{playerId}` authorizes only if the requesting player's cache-backed token resolves to that `playerId` — reuses `Cache::get("access-token:$token")`, no new auth mechanism.
3. Public channel `bird-escape.round` needs no auth (multiplier ticks and bird events are public, matching the spec: "all players see birds release in sync").
4. Add `infra/systemd/reverb.service` unit (mirrors the existing Horizon unit precedent) for production process supervision.
5. Smoke test: a trivial `TestPing` event broadcast on `bird-escape.round`, one PHPUnit test asserting the event implements `ShouldBroadcast` and serializes the expected channel name (a real WS round-trip isn't unit-testable — cover that in Phase 7's manual QA pass instead).

**Files:** `config/broadcasting.php` (new), `routes/channels.php` (new), `.env.example` (modify), `infra/systemd/reverb.service` (new).

---

## Phase 2 — Data model

**Tasks:**
1. Migration `create_bird_escape_rounds_table`:
   - `id`, `status` (`BETTING|LIVE|CRASHED|SETTLED`), `serverSeedHash` (published at round start), `serverSeedHex` (nullable, filled in only on reveal after crash), `nonce` (public, e.g. round sequence number), `crashMultiplierHundredths` (nullable until crash — never sent to clients pre-crash), `bettingEndsAt`, `startedAt`, `crashedAt`, `createdAt`.
   - Mirrors `FairnessSeed`'s hash-chain idea: also store `previousRoundHash` for auditability.
2. Migration `create_bird_escape_bets_table`:
   - `id`, `roundId`, `playerId`, `slotIndex` (`1|2`), `stakeKobo`, `autoCloseMultiplierHundredths` (nullable), `status` (`PENDING|CLOSED|LOST`), `closedAtMultiplierHundredths` (nullable), `payoutKobo` (nullable), `idempotencyKey` (unique, same replay-safety pattern as `Ticket.idempotencyKey`), `createdAt`.
3. Seed `GameRegistry` row: `gameCode = 'BIRDESCAPE'`, `engineVersion = 'birdescape-1.0.0'`, `status = 'SUSPENDED'` until launch, `minStakeKobo`/`maxStakeKobo` from product, `enabledChannels = ["web"]`.
4. Models: `BirdEscapeRound`, `BirdEscapeBet` (thin Eloquent models, no business logic — matches `Ticket`/`TicketOutcome` split).

**Files:** `database/migrations/2026_XX_XX_*.php` (new x2), `database/seeders/BirdEscapeGameSeeder.php` (new, mirrors `BlackRedGameSeeder.php`), `app/Models/BirdEscapeRound.php`, `app/Models/BirdEscapeBet.php`.

---

## Phase 3 — Provably-fair crash engine (pure function, like `BlackRedEngine`)

**Tasks:**
1. `app/Domain/Games/Engine/BirdEscape/BirdEscapeCrashEngine.php` — pure, deterministic, no I/O (same contract as `BlackRedEngine::resolve`, verified the same way by `EnginePurityTest`):

```php
final class BirdEscapeCrashEngine
{
    public const VERSION = 'birdescape-1.0.0';
    private const HOUSE_EDGE_INSTANT_CRASH_MOD = 33; // ~3% of rounds crash at 1.00x — TUNE + COMPLIANCE REVIEW

    /** Commit phase: publish this hash before the round starts. */
    public function commit(string $serverSeedHex): string
    {
        return hash('sha256', $serverSeedHex);
    }

    /** Resolve phase: deterministic crash multiplier from (serverSeed, publicNonce). */
    public function resolveCrashPoint(string $serverSeedHex, string $nonce): int
    {
        $hmac = hash_hmac('sha256', $nonce, $serverSeedHex);
        $int52 = hexdec(substr($hmac, 0, 13)); // top 52 bits

        if ($int52 % self::HOUSE_EDGE_INSTANT_CRASH_MOD === 0) {
            return 100; // 1.00x, hundredths precision
        }

        $e = 2 ** 52;
        return intdiv(100 * $e - $int52, $e - $int52); // multiplier in hundredths
    }
}
```

2. Unit tests: known-vector determinism (same seed+nonce → same crash point, always), distribution sanity (large-N sample average multiplier matches the formula's expected value within tolerance), and a purity test alongside the existing `EnginePurityTest`.
3. `app/Domain/Fairness/BirdEscapeSeedIssuer.php` — adapts `SeedIssuer`'s CSPRNG + `previousHash` chaining, but two-phase: `issueCommitted()` (generates seed, stores it *unrevealed*, returns only the hash) and `reveal(FairnessSeed $seed)` (returns the seed hex, called only after crash).

**Files:** `app/Domain/Games/Engine/BirdEscape/BirdEscapeCrashEngine.php`, `app/Domain/Fairness/BirdEscapeSeedIssuer.php`, `tests/Unit/BirdEscapeCrashEngineTest.php`.

---

## Phase 4 — Round loop (server-authoritative tick broadcaster)

**Tasks:**
1. `app/Domain/Games/BirdEscape/RoundStateStore.php` — thin Redis wrapper (reuses the already-wired Predis client) holding exactly what both the loop process and HTTP request processes need to agree on: `roundId`, `phase`, `startedAt` (wall-clock timestamp), `crashAtElapsedMs` (derived from the crash multiplier via the growth formula — kept server-side only, never serialized to the client). This is the seam that lets a stateless HTTP controller (Phase 5) independently compute "what multiplier is it right now" without talking to the loop process.
2. `app/Domain/Games/BirdEscape/MultiplierClock.php` — pure function `multiplierAt(int $elapsedMs): float` implementing `1 + k * elapsedSeconds`; `k` is a config value (`config('birdescape.growth_k')`), tunable without a deploy.
3. `app/Console/Commands/RunBirdEscapeRoundsCommand.php` — the long-running loop:
   - BETTING phase: create round row, `commit()` the seed hash, broadcast `RoundBettingOpened` (public channel), sleep for the configured betting window (e.g. 5s).
   - LIVE phase: loop at 100ms resolution — compute `multiplierAt(elapsed)`, broadcast `RoundTick` whenever the multiplier crosses a new whole number (fires `BirdReleased` alongside), auto-close any `PENDING` bet whose `autoCloseMultiplierHundredths` has been reached (settles via `WalletService::settleWin`, same as Phase 5's manual close-gate path), stop when `elapsed >= crashAtElapsedMs`.
   - CRASH: broadcast `RoundCrashed` with the revealed seed (`BirdEscapeSeedIssuer::reveal`) so any observer can independently verify `commit()`/`resolveCrashPoint()` matched what was published pre-round; settle every still-`PENDING` bet as `LOST` via `WalletService::settleLoss`.
   - Loop back to BETTING.
4. `infra/systemd/bird-escape-round-loop.service` — supervised process, same precedent as Reverb/Horizon. **Verify locally first**: unlike Horizon, this loop does no forking/signal handling, so it likely runs fine on the Windows dev sandbox despite the missing pcntl/posix extensions that blocked Horizon — confirm with a short local run before assuming it needs the Linux-only deferral Horizon got.

**Files:** `app/Domain/Games/BirdEscape/RoundStateStore.php`, `app/Domain/Games/BirdEscape/MultiplierClock.php`, `app/Console/Commands/RunBirdEscapeRoundsCommand.php`, `app/Events/BirdEscape/{RoundBettingOpened,RoundTick,BirdReleased,RoundCrashed}.php`, `config/birdescape.php` (growth `k`, betting window, tick interval), `infra/systemd/bird-escape-round-loop.service`.

**Tests:** `MultiplierClock` and `RoundStateStore` are both plain-function/Redis-backed and unit-testable without running the full loop. The loop command itself gets one integration test that runs it for a few ticks against a fake clock and asserts the expected event sequence.

---

## Phase 5 — Bet placement & close-gate ("Close the Gate") API

**Tasks:**
1. `app/Domain/Games/BirdEscape/PlaceBirdEscapeBet.php` — mirrors `CreateTicket`'s two-phase shape (eligibility outside a transaction, commit inside one): validates round is in `BETTING`, slot 1 or 2, stake within `GameRegistry` bounds, runs the same RG/KYC/registry gates `CreateTicket` runs (`LimitsService`, `ProtectionService`, `RegistryCheckService`), then `WalletService::reserveStake` + insert `BirdEscapeBet` row, idempotent on `idempotencyKey` exactly like `Ticket`.
2. `app/Domain/Games/BirdEscape/CloseBirdEscapeGate.php` — the cash-out action: locks the round row, checks `phase === LIVE` and `elapsed < crashAtElapsedMs` (read from `RoundStateStore`), computes `multiplierAt(elapsed)` server-side (never trusts a client-sent multiplier, per spec), marks the bet `CLOSED`, calls `WalletService::settleWin`. If the lock reveals the round already flipped to `CRASHED`, rejects with the tie-break policy from the Open Decisions section.
3. `app/Http/Controllers/Api/V1/BirdEscapeController.php` — `show()` (game meta, same shape as `BlackRedController::show`), `placeBet()`, `closeGate($betId)`.
4. `routes/v1.php` additions, following the existing block's shape:
   ```php
   Route::get('/games/birdescape', [BirdEscapeController::class, 'show']);
   Route::post('/birdescape/bets', [BirdEscapeController::class, 'placeBet'])->middleware('idempotent');
   Route::post('/birdescape/bets/{bet}/close', [BirdEscapeController::class, 'closeGate']);
   ```

**Files:** as listed above; `app/Http/Requests/Api/V1/PlaceBirdEscapeBetRequest.php` (mirrors `PurchaseTicketRequest`).

**Tests:** feature tests mirroring the existing ticket-purchase test style — insufficient balance, idempotent replay, bet-on-non-betting-round rejection, close-after-crash rejection, dual-slot independence.

---

## Phase 6 — Auto-close protection

Already implemented as part of Phase 4's tick loop (every tick evaluates every `PENDING` bet's `autoCloseMultiplierHundredths`). This phase is just: capture `auto_close_multiplier_hundredths` as an optional field on `PlaceBirdEscapeBetRequest`/`PlaceBirdEscapeBet`, and add the feature test that a disconnected player's bet still closes at their preset target with no client involved.

---

## Phase 7 — Frontend

Follows the exact module shape BlackRed already established under `apps/web/src`:

**Tasks:**
1. `mocks/birdescape.ts` — mock round/tick/bet responses (same role as `mocks/blackred.ts`), used by component tests before the backend is wired.
2. `components/games/BirdEscapeGameFlow/BirdEscapeGameFlow.tsx` — presentational: renders the cage/multiplier/bird animation state and two bet-slot controls, given props (no data fetching) — matches the container/presentational split BlackRed uses.
3. `components/games/BirdEscapeGameFlow/ConnectedBirdEscapeGameFlow.tsx` — container: owns the Echo subscription (`bird-escape.round` public channel for ticks/birds/crash, `bird-escape.player.{id}` private channel for this player's bet-slot state), dispatches place-bet/close-gate calls, feeds `BirdEscapeGameFlow`.
4. `lib/echo.ts` (new, shared) — one Laravel Echo client instance configured for Reverb, reused by any future realtime game (not BlackRed-specific).
5. `components/games/birdEscapeErrors.ts` — error-code → message mapping (mirrors `blackRedErrors.ts`).
6. Routes: `app/(game)/games/birdescape/page.tsx`, `app/(player)/games/birdescape/page.tsx` (mirrors the existing BlackRed route-group duplication).
7. `BirdEscapePlayModal` — the two-slot stake entry + optional auto-close-target input.

**Tests:** component tests per the existing `*.test.tsx` pattern, driven off `mocks/birdescape.ts` (no live WS needed for these); one Playwright/e2e smoke test that places a bet against a running dev backend and asserts a close-gate payout renders — per the web testing rules, actually exercise this in a browser before calling the phase done.

---

## Phase 8 — Round history & fairness verification

**Tasks:**
1. Public endpoint `GET /v1/birdescape/rounds/{id}/verify` — returns `serverSeedHash`, revealed `serverSeedHex`, `nonce`, `crashMultiplierHundredths`, so anyone can recompute `commit()`/`resolveCrashPoint()` independently and confirm it matches — this satisfies the spec's "public round-history log to verify past crash points" directly, no new page needed beyond a simple list view.
2. Back-office read surface for round/bet history — same pattern as the existing `DailySummaryController`/backoffice route-guard architecture (per prior backoffice work); reuse, don't reinvent.

**Files:** `app/Http/Controllers/Api/V1/BirdEscapeVerificationController.php`, `app/Http/Controllers/BackOffice/BirdEscapeRoundsController.php`.

---

## Phase 9 — Anti-fraud / limits (verify, don't build)

`PlaceBirdEscapeBet` (Phase 5) already calls `LimitsService`, `ProtectionService`, `RegistryCheckService`, and `VelocityService` the same way `CreateTicket` does — this phase is a review pass confirming those services' existing rules generalize to a continuous multi-round game (e.g., `VelocityService::evaluateAfterTicket`-equivalent hook fires per bet, not per round) rather than new anti-fraud code.

---

## Suggested build order

Phases 1→2→3 can run in parallel (independent). Phase 4 depends on 2+3. Phase 5 depends on 4. Phase 6 is free once 4+5 land. Phase 7 depends on 1+5. Phases 8–9 are independent tail work, safe to defer past initial launch if needed — flag if you want them cut from v1 scope.
