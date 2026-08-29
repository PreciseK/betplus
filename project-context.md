# Betplus — Project Context

Rules for any agent or engineer writing code in this repository. Derived from
`Betplus_PRD.md`. Where this file and the PRD disagree, the PRD wins — but tell someone,
because it means this file is stale.

Requirement IDs in parentheses are the PRD clauses these rules enforce.

---

## 1. What this is

**Betplus is the platform. BlackRed and Heritage are games inside it.** A player has one
identity, one wallet, one transaction history and one compliance perimeter across every game
and every channel.

Market: Nigeria. Payment rail: OPay, exclusively. Channels: responsive Web, React Native app
(Android and iOS from one codebase), USSD.

**The design test:** adding a third game must be an adapter plus configuration, not a
re-platform. If a change would make a third game harder, it is the wrong change.

## 2. Non-negotiable rules

These are the ones that cause incidents, licence breaches or lost player money when broken.

### Money

1. **All money is integer kobo.** `BIGINT`, never float, never decimal, never a string.
   ₦1.00 = 100 kobo. Carry the denomination in the identifier: `amountKobo`, `stakeKobo`,
   `cachedBalanceKobo`. (`REQ-WAL-003`)
2. **The Wallet Service is the only writer to `ledgerEntry`, `wallet` and `walletTransaction`.**
   No engine, no channel adapter, no script, in any language. Database grants enforce this —
   do not work around them. (`REQ-ARCH-001`, `REQ-ARCH-002`)
3. **Every money movement is double-entry and balances to zero.** A non-zero journal is a P0.
   (`REQ-WAL-001`)
4. **The ledger is append-only.** No `UPDATE`, no `DELETE`. Corrections are compensating
   entries with recorded justification. (`REQ-WAL-040`, `REQ-BO-006`)
5. **Every mutating operation carries a caller-supplied idempotency key.** Called 100× with the
   same key, exactly one effect. (`REQ-WAL-042`, `REQ-QA-008`)
6. **A won prize is never lost.** Provider outage, float exhaustion, dropped session or
   abandoned ticket may delay a payout. None may remove it from the Winnings Balance.
   (`G5`, `REQ-PO-009`, `REQ-FLOAT-006`, `REQ-TKT-006`)

### Games

7. **A game engine is a pure function of its inputs.** Same `(seed, ticket_id, input)` →
   byte-identical output, on every host, restart and deployment of that engine version.
   (`REQ-GEC-001`)
8. **Engines hold no state.** No database, no external calls, no filesystem writes.
   (`REQ-GEC-002`)
9. **Engines never generate randomness.** The seed comes from the platform Fairness Service.
   (`REQ-GEC-003`)
10. **Outcomes never depend on house revenue, time of day, player history, or anything other
    than the seed, the stake and the prize table.** An engine that consults shared mutable state
    to decide whether a player may win cannot be certified, cannot publish odds and cannot be
    replayed. (`REQ-GEC-001`, `REQ-BR-013`)
11. **Prize tables are versioned configuration, never code.** Tier probabilities sum to exactly
    1.0000. Every ticket records the table version in force and settles under it forever.
    (`REQ-GEC-020`, `REQ-GEC-021`, `REQ-GEC-022`)
12. **95% RTP ceiling, platform-wide, every tier.** This is an AML control under `BP-AML-001`
    §7.2, not a margin preference. Publication is gated on actuarial certification.
    (`REQ-GEC-023`, `REQ-GEC-025`)
13. **Outcome is determined at ticket creation.** The reveal is presentation. No response before
    the reveal completes may carry unrevealed information — including response size and timing.
    (`REQ-TKT-004`, `REQ-TKT-005`)

### Randomness

14. **Prohibited in PHP anywhere in the game or money path:** `rand()`, `mt_rand()`,
    `shuffle()`, `array_rand()`, `str_shuffle()`. Static analysis fails the build on these.
    (`REQ-RNG-008`)
15. **Prohibited in the Heritage Python engine:** the `random` module, for anything touching
    outcome. (`REQ-RNG-009`)
16. Use `random_bytes()` / OS entropy via the Fairness Service. Seed records are WORM with hash
    chaining. (`REQ-RNG-001`, `REQ-RNG-004`)

### Fail closed

17. **Two dependencies block play when unavailable: geolocation and the exclusion registry.**
    Below `geo_min_confidence` (0.80), or with a registry cache older than
    `safeplay_max_staleness` (6h), ticket creation is refused. Never default to a state, never
    assume not-excluded. (`REQ-GEO-005`, `REQ-RG-014`)
18. Everything else degrades gracefully — draw partner, notifications and analytics outages must
    not block play. (`REQ-NFR-023`)

### Channels

19. **Every channel is the same game.** Same board, same odds, same prize table. A channel may
    render differently; it may never present a different mechanic. (`REQ-HG-005`, `REQ-QA-010`)
20. **The client renders. It never decides** an outcome, a balance or an eligibility.
21. USSD screens are ≤160 characters including the prompt, in every locale. The build fails on
    overflow. (`REQ-QA-013`)
22. USSD outcomes are always mirrored to SMS — the screen is transient. (`REQ-NOT-008`)

### Data protection

23. **NIN and BVN live in a separate vault with independent access control.** Never logged,
    never sent to analytics, never returned in full by any API. Services receive tokens.
    (`REQ-ID-023`, `REQ-SEC-010`, `REQ-NFR-041`)
24. **No secrets in source control.** OPay RSA private keys especially. Secret scanning runs in
    CI. (`REQ-SEC-005`)
25. Never infer a player's state from their MSISDN prefix. Nigerian numbers are portable.
    (`REQ-GEO-003`)

### Conduct

26. **No dark patterns.** Prohibited: pre-selected maximum stakes, "one more play" prompts,
    countdown offers on a loss screen, obscured loss framing, celebratory treatment of a loss,
    replay as the visually dominant action on a loss, highlighted near-misses.
    (`REQ-RG-022`, `REQ-HG-014`)
27. Colour is never the sole carrier of meaning — this matters most in BlackRed, whose whole
    interface is a red/black distinction. (`REQ-NFR-050`, `REQ-BR-022`)

## 3. Two integration traps

Both are documented in the PRD because they are easy to get wrong and expensive to find later.

**Signature schemes.** OPay collections sign with **HMAC-SHA512**; payouts sign with
**RSA-SHA256**. Both send `Authorization: Bearer {signature}` and a `MerchantId` header, so a
mix-up looks identical on the wire. Separate signer classes, separate config, separate tests.
(`REQ-PAY-010`, `REQ-QA-015`)

**Units.** Payout *requests* carry `amount` in **kobo**. Payout *callbacks* describe `amount` in
**Naira** (`"2000.00"`). Parsing the callback as kobo produces a 100× reconciliation error on
every payout. (`REQ-PO-012`, `REQ-QA-014`)

## 4. Naming and conventions

| Concept | Use | Never |
|---|---|---|
| Product | Betplus | Buzzicash, BlackRed Raffle Platform |
| Winnings balance | `PLAYER_WINNINGS`, "Winnings Balance" | `PLAYER_PAYOUT` |
| Money fields | `*Kobo` | `*Pesewas`, bare `amount` |
| Currency | `NGN`, explicit on every amount | implicit |
| BlackRed | "instant fixed-odds prediction" | "raffle" |
| Withdrawal control | 1× turnover | 50% rule, proportional cap |

- Requirements are `REQ-<MODULE>-<NNN>`. **MUST** = launch-blocking, **SHOULD** = deferrable
  with written sign-off, **MAY** = optional.
- PHP: PSR-4, PSR-12, `declare(strict_types=1)`, constructor injection, prepared statements only.
- Python: the Heritage engine only. Multi-process deployment, never threads — the GIL will not
  meet the throughput target. (`REQ-HOST-004`, `REQ-NFR-034`)
- No tax rate, prize value, limit or jurisdiction rule is ever a constant in code. All of it is
  effective-dated configuration under maker-checker. (`REQ-TAX-002`, `REQ-GEC-020`)

## 5. Hosting reality

cPanel/WHM on a VPS or dedicated server with root. Nginx terminates TLS and reverse-proxies to
PHP-FPM (platform + BlackRed) and to Passenger/Gunicorn (Heritage). Engine endpoints bind to
loopback only. MariaDB 10.6+, Redis with AOF. Queue workers are supervised long-running
processes, never cron. Cron is for genuinely scheduled work only. (`REQ-HOST-001`–`011`)

**The static egress IP is a hard dependency under change control** — OPay IP-whitelists in both
directions, and changing the IP breaks payments. (`REQ-HOST-002`, `REQ-PAY-011`)

Deployment is scripted and atomic with symlinked release directories and rehearsed rollback.
Editing files through cPanel File Manager in production is prohibited. (`REQ-HOST-011`)

## 6. Inherited defects — do not re-introduce

The existing `BlackRed/EngineAndServices` codebase is documented in `docs/`. Seven findings
there constrain this build. Four are patterns that must not survive into the target:

1. **A game engine that owns money, state and randomness** and decides outcomes from the
   house's daily net revenue. Replaced by the Engine Contract and a certified prize table.
2. **A channel that re-implements the engine** and writes the ledger itself. Channels are
   adapters over the platform API and hold no money logic.
3. **Migrations that do not build a working database.** Every schema object a code path touches
   has DDL in version control. No exceptions, no "it exists in production".
4. **Secrets in the working tree.** See rule 24.

Read `docs/index.md` before touching anything inherited.

## 7. Open items

Section 20 of the PRD lists items awaiting third-party confirmation. **Nothing is built on an
assumption listed there.** The ones that block code rather than commercials: OQ-06 (BlackRed's
certified multipliers), OQ-07 (Heritage tier probabilities and `TIER_HIGH`), OQ-09
(second-chance draw entry form), OQ-10 (Heritage regional coherence model).

If you need one of these to proceed, stop and ask — do not pick a plausible value.
