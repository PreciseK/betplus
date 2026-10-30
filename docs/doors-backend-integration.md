# Doors backend: Model 1 integration

## Delivered behaviour

`/games/doors/` uses the account wallet and the platform API. `/doors-demo/` remains an explicitly labelled, independent visual demo. The live adapter never falls back to fabricated balances, local prize draws or client-side settlement when a request fails.

- Twenty doors, up to five distinct attempts, and a single 10-second round. Stakes start at ₦200 (platform-wide minimum).
- First treasure ends the round immediately: gold bars 3x, chest 5x, card 2x the stake.
- Five empty attempts or server-side timeout settle a loss. Duplicate picks do not consume attempts.
- Stakes debit the existing bonus/play wallet priority through `WalletService`. Wins credit the winnings wallet through the same balanced ledger service. All stored/API amounts are integer kobo; the UI displays naira.
- Gross prizes, actual net winnings, withholding and any reclaimed bonus principal are separately displayed. Example under the existing resident tax config: a 100-naira stake wins 300 naira gross on gold, with 15 naira withholding and 285 naira net where the stake used no bonus.
- Big Man's transparent thumbs-up image appears beside the server-confirmed winnings popup.
- The accepted round's rules, odds version and computed tax awards are snapshotted. Pausing new play or changing config cannot remove/reprice an already accepted win.

## Model 1 and the draft odds

The chosen model is `BALANCED_HYBRID`. The new `DoorsGameSeeder` only creates a maintenance registry row and a **draft**, not an active, certified real-money game.

The draft is a reviewable proposal, not an approved commercial prize table:

| Setting | Draft value |
|---|---:|
| Hidden treasures per board | Exactly one, uniformly placed among 20 doors |
| Treasure-type distribution | Gold 56%, chest 28%, card 16% |
| Chance of finding it in five distinct attempts | 25% before timeouts |
| Maximum theoretical gross RTP | 85% |
| Expected gross house hold | 15% before variance, fees, promotions and operating costs |
| Kelly-style maximum stake | 1% of the latest float |
| Reserve allocation | 20% of positive daily net GGR, through the existing reserve-siphon service |
| Minimum protected funding floor | 1,000,000 kobo (10,000 naira) |
| Maximum float snapshot age | 120 seconds |
| Stake limits | 10,000–50,000 kobo (100–500 naira) |

RTP math: `(0.56 × 3 + 0.28 × 5 + 0.16 × 2) × 5/20 = 0.85`. The new publication gate checks the distribution sum, exact approved multipliers and the existing platform ceiling of 88% using integer arithmetic. Shorter play that ends before all five attempts can have a lower realised return.

**Positive expected margin does not guarantee profit on each round, day or business period.** Acceptance controls do not alter an accepted board or force losses after wins.

## Funding protection

Doors locks its game-registry row while accepting/picking/settling, so concurrent Doors requests cannot allocate the same headroom independently. Before debiting a stake it applies the existing Model 1 cap and reserves the maximum gross prize (5x stake) for that open round.

The additional headroom check deducts customer play/winnings balances, credit balances in reserve/tax/suspense/prize/promotion liabilities, pending withdrawals, payouts confirmed since the float snapshot, other open Doors exposure, and the configured funding floor. Missing/stale funding fails closed. Some amounts overlap intentionally, making acceptance conservative.

This is a Doors reservation boundary, not a new platform-wide reservation engine: other games do not acquire the Doors lock or reserve all their possible future awards through it. Production load/concurrency testing against MariaDB and a unified cross-game treasury review remain necessary before real-money activation. Local tests use SQLite and cannot prove production row-lock behaviour.

## Server ownership and API

All routes require the existing player access token/cookie. Every POST requires `idempotency_key`; the shared middleware also accepts the `Idempotency-Key` header. The adapter sends both with the same value.

| Route | Purpose |
|---|---|
| `GET /v1/games/doors` | Rules, activation state, wallet, active and latest round for recovery |
| `POST /v1/doors/rounds` | Start: `stake_kobo`, `duration_seconds`, `idempotency_key` |
| `GET /v1/doors/rounds/{reference}` | Resume authoritative state, settling expiry if necessary |
| `POST /v1/doors/rounds/{reference}/open` | Reveal: zero-based `door_index` and `idempotency_key` |

The platform Fairness Service issues a seed. The pure `DoorsEngine` deterministically chooses the treasure and door using HMAC plus rejection sampling. The board is stored encrypted and committed at round creation. API responses contain only revealed doors, a fixed-length commitment, status and wallet/settlement fields; they never contain the seed or hidden board.

Ownership is checked on every read/pick. Start/pick retries reuse the original request key. After POST the adapter fetches current round state so an old idempotency response cannot restart a stale countdown. A transport failure retains the exact operation for retry. Reloads recover an active/latest server round; an active round bypasses the intro so paid time is not spent replaying it.

The existing authenticated operator replay endpoint now dispatches Doors tickets to the Doors engine. It refuses active rounds (409), then after settlement checks the sealed board/commitment, revealed picks, gross award and outcome digest. A drifted board fails the comparison rather than silently being treated as a BlackRed ticket.

`doors:expire-rounds` runs every minute through the existing scheduler to settle abandoned rounds and release reservations. Winning settlement uses the existing queued payout mechanism; failure of the external payout rail does not erase the wallet credit.

## Publication and activation

The existing economics-config CRUD and maker-checker workflow now support Doors-specific settings and validation. The operations form preserves the complete Doors parameter object when editing.

Before activation:

1. Apply migrations and run `php artisan db:seed --class=DoorsGameSeeder`.
2. Review the draft odds, funding floor, stake limits and reserve settings. Record an actual certification reference in `params.doors.certification_ref`. Production refuses empty/placeholder references.
3. Propose and approve `game_economics_config_publish` through separate maker/checker accounts.
4. Confirm jurisdiction/registry, queue, scheduler and fresh float monitoring are operational.
5. Explicitly set the registry to `ACTIVE` and `DOORS_ENABLED=true` in the intended deployment.

No production migration, publication, activation, cash funding or external payout was performed during this implementation.

## Isolated local integration preview

```powershell
pwsh -NoProfile -File tools/doors/start-local-backend.ps1
```

This creates/reuses `output/doors-local/doors-local-preview.sqlite`, an ignored `.env.doors-local`, and a local-only test account. It refuses to seed any other environment/database. The fixture publishes explicitly test-labelled odds only in that isolated database and uses a database queue without running external payment jobs.

In another terminal:

```powershell
pwsh -NoProfile -File tools/doors/start-local-frontend.ps1
```

The frontend runs at `http://localhost:3102/games/doors/`, with API `http://localhost:8001/v1` and separate Next build output. Browser test auth state is saved to ignored `output/playwright/doors-local-auth.json`; it is a temporary test session, not a production account or a sign-in bypass endpoint. Refresh it by rerunning the PHP local fixture with `APP_ENV=doors-local`.

## Validation

- 64 targeted PHP tests passed, covering Doors, operator replay/drift detection, maker-checker economics publication, wallet ledger, existing BlackRed tickets and reserve behaviour.
- 9 frontend rules/catalogue tests passed; TypeScript passed.
- PHPStan passed on the new Doors domain, controller, model, expiry command, seeder and updated operator replay controller.
- The production frontend build completed successfully and generated all 75 static pages, including `/games/doors/`.
- Browser-to-local-API verification observed a server gold win of 30,000 kobo gross, 1,500 withheld and 28,500 net. Response-loss recovery retained the same round and attempt without another debit. A reload restored an active round and bypassed the intro.
- Desktop/mobile viewport screenshots live under `output/playwright/doors-backend-*`. Physical-phone, production-provider delivery and MariaDB stress tests are not implied by these checks.
- The legacy repository-wide gate scripts are not reported as passing. They flag Heritage test-seed randomness, the existing demo-player seed command, and many naming matches (including non-money type identifiers). Generated output and ignored tool worktrees are now pruned from traversal; the pure Doors engine is explicitly registered for strict scanning. These broad gate findings need separate triage before a full release-clean claim.
