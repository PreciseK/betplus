# Caged USSD Engine — Design Spec

**Date:** 2026-09-09
**Status:** Approved for planning
**Source:** `docs/caged-ussd-complete-flows.md` (Option B — "Escape Count")

## 1. Scope

Build Caged as a third instant-resolve ticket game on `apps/platform`, exposed to
players over `apps/ussd`. Only **Option B** ("Escape Count": player picks how many
of 5 birds escape before the cage slams shut, 1-5) is built. Option A (pre-set
target multiplier riding the live crash round) is explicitly out of scope — it
needs BirdEscape's live shared-round/cashout machinery, which doesn't fit a
~25-second USSD session, and the source doc itself recommends against it for
USSD.

As part of this work, **USSD-initiated deposits are removed platform-wide**:
players fund and withdraw directly through the OPay/Betplus app now, so the
standalone "Fund account" menu flow and the in-flow "insufficient balance → OPay
OTP top-up → retry" fallback (previously in BlackRed/Heritage purchase, and
never built for Caged) are deleted from `apps/ussd`, not just omitted from the
new game.

## 2. Architecture

Caged is structurally identical to BlackRed/Heritage: a pure engine, a domain
service (`CreateCagedTicket`) doing eligibility → resolve → single commit
transaction, a controller exposing three `/v1` routes, and a `GameRegistry` +
`PrizeTable` row seeded like the other games. No live round, no polling, no
WebSocket — the whole play resolves inside one HTTP request/response, same as
BlackRed and Heritage today.

```
USSD caller
  → apps/ussd MenuEngine (caged_pick → caged_stake → caged_confirm)
  → PlatformClient::purchaseCagedTicket()
  → POST /v1/caged/tickets  (apps/platform)
  → CagedController::purchase()
  → CreateCagedTicket::create()
      → PrizeTableResolver::resolveFor('CAGED', stateCode)
      → CagedEngine::resolve(seed, targetBirds, stakeKobo, tiers)
      → Wallet/Tax/RG/Analytics (existing, game-agnostic)
  ← Ticket + TicketOutcome
  ← USSD result screen (win/loss), rendered same turn — no SMS-fallback path
```

## 3. Engine — `App\Domain\Games\Engine\Caged\CagedEngine`

Pure function, no DB/IO/internal randomness — same purity contract as
`BlackRedEngine`/`BirdEscapeEngine` (REQ-GEC-001/002/003), swept by the same
style of `EnginePurityTest`.

```php
final class CagedEngine
{
    public const VERSION = 'caged-1.0.0';

    /** @param list<CagedTier> $tiers exactly 5 tiers, targetBirds 1..5, no gaps */
    public function resolve(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult;

    /** Same inputs, byte-identical output — resolve() is already pure. */
    public function replay(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult;
}
```

**Outcome model — direct categorical draw, no multiplier concept:**

Each `CagedTier` stores a **cumulative** win probability — `P(escapedBirds >=
targetBirds)` — exactly the "at least N birds" figures from the doc's table
(71.80%, 46.20%, 23.10%, 11.40%, 4.80% for targets 1-5). This is the single
source of truth: it's what §4's RTP gate validates directly
(`probability × multiplier`), what `show()` exposes to the player alongside
the multiplier, and what the engine derives its internal outcome buckets from
— there is no separately-configured "exact escaped-count" table to keep in
sync with it.

1. One HMAC draw: `hash_hmac('sha256', "CAGED:{ticketReference}", $seed)` →
   first 4 bytes unpacked as `uint32` → normalized uniform `$r = ($h % 100_000) / 100_000.0`,
   same normalization style as `BirdEscapeEngine::resolve()`.
2. From the 5 tiers' cumulative probabilities `T[1..5]`, with `T[0] := 1.0`
   and `T[6] := 0.0` by definition, compute six ascending boundaries
   `L[k] := 1 - T[k]` for `k = 0..6`:

   | escapedBirds (k) | T[k] = P(≥k) | L[k] = 1−T[k] | bucket = [L[k], L[k+1]) |
   |:---:|:---:|:---:|:---:|
   | 0 | 100.00% (def.) | 0.0000 | [0.0000, 0.2820) |
   | 1 | 71.80% | 0.2820 | [0.2820, 0.5380) |
   | 2 | 46.20% | 0.5380 | [0.5380, 0.7690) |
   | 3 | 23.10% | 0.7690 | [0.7690, 0.8860) |
   | 4 | 11.40% | 0.8860 | [0.8860, 0.9520) |
   | 5 | 4.80% | 0.9520 | [0.9520, 1.0000) |

   `escapedBirds` is the unique `k` in `0..5` with `L[k] <= $r < L[k+1]`. Each
   bucket's width (`L[k+1] − L[k]`, i.e. 28.20/25.60/23.10/11.70/6.60/4.80%)
   falls out of this arithmetic automatically — it is a derived quantity, not
   a stored one, so there's nothing that can drift out of sync with the
   tiers' cumulative probabilities.
3. `$won = $escapedBirds >= $targetBirds`; `$grossPrizeKobo = $won ? intdiv($stakeKobo * $tier->multiplierHundredths, 100) : 0`,
   where `$tier` is the `CagedTier` matching `$targetBirds`.
4. `digest` — same `hash_hmac`-of-inputs pattern as `Engine\BlackRed\Digest`/`Engine\BirdEscape\Digest`.

```php
final readonly class CagedEngineResult
{
    public function __construct(
        public int $escapedBirds,   // 0..5, the realized outcome
        public int $targetBirds,    // 1..5, the player's pick
        public bool $won,
        public int $grossPrizeKobo,
        public string $digest,
        public string $engineVersion,
    ) {}
}

final readonly class CagedTier
{
    public function __construct(
        public int $targetBirds,             // 1..5
        public int $probabilityNumerator,     // exact-count width, e.g. 2560 / 10000 for target 1
        public int $probabilityDenominator,   // 10000 (basis-points-style denominator)
        public int $multiplierHundredths,     // 125, 190, 380, 750, 1800
    ) {}
}
```

## 4. Prize table & publication gate

Reuse the existing `PrizeTable` model **and its existing generic
`prizeTableTier` table/`tiers()` relation** — no new migration or model is
needed. `PrizeTableTier`'s columns (`prizeTableId`, `positions`,
`multiplierHundredths`, `probabilityNumerator`, `probabilityDenominator`) are
already exactly Caged's tier shape (BlackRed already uses this same table);
Caged's `positions` column holds `targetBirds` (1-5), scoped safely by its own
`PrizeTable` row's `prizeTableId` (`gameCode = 'CAGED'`), so there's no
collision with BlackRed's own 1-5 `positions` rows under a different
`prizeTableId`. `CagedTier` (§3) is therefore a plain constructor argument
built from `PrizeTableTier` rows at the call site, not a new Eloquent model.

`App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate` (mirrors
`PrizeTablePublicationGate`):

- Per tier, `probabilityNumerator / probabilityDenominator` must equal the
  documented exact-count width from §3's table (a direct equality check
  against the modelled constant — these are empirically-chosen game-design
  frequencies, not something derivable from a combinatorial formula the way
  BlackRed's fair-coin check is).
- Per tier, gross RTP (`probability × multiplierHundredths × 100`, in basis
  points) and net-of-WHT RTP must both stay ≤ 9500bp (all five tiers clear
  this per the doc: 89.75/87.78/87.78/85.50/86.40%).
- `actuarialCertRef` must be present (REQ-GEC-025 parity, same carve-out as
  the existing gates — the real actuarial workflow is not fabricated here).

No new resolver class is needed: `App\Domain\Games\PrizeTable\PrizeTableResolver`
(the same one `CreateTicket`/`BlackRedController` already use) is fully
generic — `resolveFor(string $gameCode, string $stateCode, ...)` — so
`CreateCagedTicket`/`CagedController` inject it directly and call
`resolveFor('CAGED', $stateCode)`. (Only Heritage needed a dedicated
`HeritagePrizeTableResolver`, because its `PrizeTable` relation —
`heritageTiers` — differs from the generic `tiers()` relation Caged reuses.)

## 5. Domain service — `App\Domain\Ticket\CreateCagedTicket`

Near-clone of `CreateTicket.php` (BlackRed's — chosen over
`CreateHeritageTicket.php` as the template since Caged has no second-chance-draw
complexity):

- Constructor: same collaborators as `CreateTicket` (`AttributionService`,
  `SeedIssuer`, `PrizeTableResolver`, `CagedEngine`, `WalletService`,
  `TaxEngine`, `LimitsService`, `ProtectionService`, `RegistryCheckService`,
  `VelocityService`, `AnalyticsEventRecorder`).
- `create(Player $player, int $targetBirds, int $stakeKobo, string $idempotencyKey): Ticket`
- Same ordering: idempotency-key short-circuit → eligibility (KYC tier 1,
  protection, registry, attribution) → validate `$targetBirds` is 1..5 →
  stake-bounds check against `GameRegistry` row → limits check → prize table
  lookup → resolve engine outside any lock → single `DB::transaction` doing
  re-assertion + `Ticket::create` + `wallet->reserveStake` + `TicketOutcome::create`
  + `wallet->settleWin`/`settleLoss` + `Ticket::update(['status' => 'SETTLED'])`.
- `Ticket.gameCode = 'CAGED'`, `predictionJson = [$targetBirds]`,
  `positions = $targetBirds` (reusing the existing int column — same 1-5 range
  as BlackRed's `positions`, different semantic label at the call site only).
- `TicketOutcome.resultJson = ['escaped_birds' => $engineResult->escapedBirds, 'target_birds' => $targetBirds]`.
- On insufficient balance / any `TicketEligibilityException`, no deposit
  fallback is attempted (per §1) — the exception propagates to the controller
  exactly as it does for BlackRed/Heritage today.
- `RevealTicket` needs no changes — already game-agnostic.

## 6. API surface

```
GET  /v1/games/caged                          CagedController::show
POST /v1/caged/tickets              [idempotent]  CagedController::purchase
GET  /v1/caged/tickets/{reference}/reveal     CagedController::reveal
```

`CagedController` mirrors `BlackRedController` field-for-field:

- `show()`: game metadata, wallet balances, turnover position, daily limit,
  stake bounds, tax config, `prize_table_version`, `engine_version`, and
  `tiers` (`target_birds`, `multiplier_hundredths`, `probability_numerator`,
  `probability_denominator`).
- `purchase(PurchaseCagedTicketRequest $request)`: reads `target_birds` (int)
  + `stake_kobo` + `idempotency_key`; response discloses nothing about the
  outcome (REQ-TKT-001/005 parity) — reference, purchased_at, target_birds,
  stake_kobo, tier, play_balance_after_kobo, status: 'purchased'.
- `reveal(string $reference)`: adds `escaped_birds`, `won`, `gross_prize_kobo`,
  `tax_withheld_kobo`, `net_credit_kobo`, `winnings_balance_after_kobo`, same
  shape as BlackRed's reveal.

New `App\Http\Requests\Api\V1\PurchaseCagedTicketRequest` (mirrors
`PurchaseTicketRequest`, validating `target_birds` is an integer 1-5 instead of
a `B`/`R` list).

## 7. Seeder — `CagedGameSeeder`

Mirrors `HeritageGameSeeder`'s structure:

- `GameRegistry`: `gameCode: 'CAGED'`, `status: 'ACTIVE'`,
  `minStakeKobo: 10_000` (NGN 100, consistent with Heritage/BlackRed rather
  than the doc's own NGN 50 example — confirmed with product), `maxStakeKobo: 2_000_000`,
  `engineVersion: CagedEngine::VERSION`.
- `PrizeTable`: `gameCode: 'CAGED'`, `stateCode: null`, a version string
  (e.g. `CG-NG-2026.1`), `status: 'published'`, `actuarialCertRef` set to a
  placeholder consistent with how other seeders record it.
- Five `prizeTableTier` rows (via `$table->tiers()->create(...)`) per §3/§4's
  table.
- Registered in `DatabaseSeeder.php` alongside the other game seeders.

## 8. USSD side — `apps/ussd`

### 8.1 Menu changes

Main menu becomes:

```
Betplus
Bal: NGN 2,500
1. Play BlackRed
2. Play Heritage
3. Play Caged
4. Responsible play
0. Exit
```

`fund_amount` and `fund_otp` screens, and the standalone "Fund account" entry
point, are removed. `blackred_pay_otp` and `heritage_pay_otp` screens and their
triggering fallback branches in `screenBlackRedConfirm`/`screenHeritageConfirm`
are also removed — on purchase failure, both now return the same end screen:
*"Could not place that ticket. Fund your wallet via the Betplus/OPay app and
try again."*

### 8.2 New Caged screens in `MenuEngine::dispatch()`

- `caged_pick` — entered from main menu option `3`:
  ```
  Caged: Birds Escaping
  Bal: NGN 2,500
  How many birds escape?
  1. 1 Bird  (1.25x)
  2. 2 Birds (1.90x)
  3. 3 Birds (3.80x)
  4. 4 Birds (7.50x)
  5. 5 Birds (18.0x)
  0. Back
  ```
  Validates input is `1`-`5` (or `0` → back to main menu); stores
  `session->data['cagedTarget']`.
- `caged_stake` — same `nairaToKobo()` validated stake entry as BlackRed/Heritage
  ("Enter stake in Naira (e.g. 200):"). Stores `session->data['cgStakeKobo']`.
- `caged_confirm`:
  ```
  Confirm Caged Bet:
  Target: N Birds
  Odds: X.XXx
  Stake: NGN Y
  Win: NGN Z
  1. Confirm & Play
  2. Cancel
  ```
  `1` → calls `PlatformClient::purchaseCagedTicket()`, then
  `revealCagedTicket()` (purchase discloses nothing, mirroring BlackRed/Heritage's
  two-call pattern), then `notifyTicketSms()`. On purchase failure → the
  shared insufficient-funds end screen from §8.1 (no OTP fallback screen for
  Caged, consistent with removing it from the other two games).
  `2` → back to main menu.
- Result end screens (rendered same turn, per REQ-USSD-005/REQ-NOT-008 the SMS
  is a courtesy backup, not the primary channel):
  - Win: `"CAGED WIN!\n{escaped} birds escaped the cage!\nYour Target: {target} Birds\nWon: NGN {net}\nRef: {reference}"`
  - Loss: `"CAGED LOSS!\nCage dropped at {escaped} bird(s)!\nYour Target: {target} Birds\nLost: NGN {stake}\nRef: {reference}"`
  - Both verified under the 160-char GSM budget by the same
    `CharacterLimit`/screen-fixture test the other games use.

### 8.3 `PlatformClientInterface` / `PlatformClient`

Add:
```php
/** @return array<string, mixed> */
public function cagedDescriptor(string $token): array;

/** @return array<string, mixed> */
public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array;

/** @return array<string, mixed> */
public function revealCagedTicket(string $token, string $reference): array;
```
implemented as `getAuthed('/v1/games/caged', ...)`,
`postAuthed('/v1/caged/tickets', ...)`,
`getAuthed("/v1/caged/tickets/$reference/reveal", ...)` — same pattern as the
Heritage trio.

Remove: `fundingQuote`, `createDeposit`, `submitDepositOtp` (no remaining
caller in `MenuEngine` after §8.1's removals). Platform-side
`/v1/wallet/deposits*` routes are untouched — they remain general wallet API
for the web/app clients, unrelated to this USSD client.

`tests/Fakes/FakePlatformClient` gains fakes for the three new Caged methods
and drops the three removed funding methods.

## 9. Testing

- **`CagedEngineTest`** (mirrors `BlackRedEngine`'s purity/determinism tests
  and `BirdEscapeEngine`'s bucket-boundary tests): same-seed replay is
  byte-identical; each of the 6 escapedBirds buckets is reachable and their
  empirical frequency over many seeds converges to the configured widths;
  `won` is exactly `escapedBirds >= targetBirds`; payout math matches
  `multiplierHundredths` exactly (integer, no float drift).
- **`CagedPrizeTablePublicationGateTest`** (mirrors
  `HeritagePrizeTablePublicationGateTest`): rejects a tier whose probability
  doesn't match the documented constant, rejects a tier breaching the 9500bp
  RTP ceiling (gross or net), rejects a missing `actuarialCertRef`, accepts
  the seeded five-tier table as-is.
- **`CreateCagedTicketTest` / `CagedTicketTest`** (mirrors
  `HeritageTicketTest.php`): happy-path win and loss, idempotency-key replay
  returns the same ticket, ineligible player (KYC/RG/registry) is rejected
  before any wallet mutation, stake outside `GameRegistry` bounds is rejected,
  insufficient balance produces the plain failure (no deposit side-effect).
- **`MenuEngineTest`** additions: full `caged_pick → caged_stake → caged_confirm`
  happy path (win and loss), invalid input at each screen re-renders with the
  `"Invalid input.\n"` prefix, `0`/back navigation, main-menu text includes
  option 3 and excludes Fund account. Existing `fund_amount`/`fund_otp`/
  `blackred_pay_otp`/`heritage_pay_otp` test cases are deleted or rewritten to
  assert the new plain-failure end screen instead.
- Screen-fixture / `CharacterLimit` check extended to the new Caged screen
  strings (≤160 chars each).

## 10. Explicitly out of scope

- Option A (pre-set target multiplier / live BirdEscape-round flow).
- The real back-office maker-checker publication workflow, the ≥10,000,000-ticket
  Monte Carlo validation run, and an actual actuarial certification — same
  carve-out `PrizeTablePublicationGate`/`CrashConfigPublicationGate` already
  document; only the structural gate class is built here.
- Locale/Pidgin/Yoruba/Igbo/Hausa copy — matches `MenuEngine`'s existing
  English-only scope note.
- Any change to platform-side `/v1/wallet/deposits*` endpoints or the web/app
  frontend's funding flows — this spec only removes the USSD app's client-side
  use of them.
