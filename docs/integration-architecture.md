# Integration Architecture

How the three parts and the external providers actually connect today.

---

## 1. Current topology

```
   ┌────────────┐   ┌──────────────┐        ┌─────────────────┐
   │  Browser   │   │ USSD gateway │        │  ANM (mobile    │
   │  (web-app) │   │   (telco)    │        │  money) · Hubtel│
   └─────┬──────┘   └──────┬───────┘        └────────┬────────┘
         │ JSON/HTTPS      │ HTTP POST               │ HTTP + callbacks
         ▼                 ▼                         │
   ┌───────────────┐  ┌──────────────┐               │
   │ public/       │  │ ussd/        │               │
   │ index.php     │  │ index.php    │◄──────────────┤
   │ [platform-api]│  │ [ussd]       │               │
   │               │  │              │               │
   │ src/Wallet/*  │  │ lib/game.php │◄──────────────┘
   │ src/Auth/*    │  │ lib/deposit  │
   │ src/Integr/*  │  │ lib/withdraw │
   └───────┬───────┘  └──────┬───────┘
           │                 │
           │   BOTH WRITE    │
           ▼                 ▼
      ┌──────────────────────────────┐
      │        MariaDB               │
      │  wallet · ledgerEntry        │
      │  walletTransaction           │
      │  dailyRevenueSummary  ◄──── shared mutable engine state
      │  gameRound                   │
      └──────────────────────────────┘
```

## 2. Integration points

| From | To | Mechanism | Notes |
|---|---|---|---|
| `web-app` | `platform-api` | JSON over HTTPS, session cookie | Only clean integration in the system |
| telco gateway | `ussd` | HTTP POST per menu turn | **Signature verification not confirmed** — see §4 |
| `platform-api` | ANM | HTTPS + HMAC signature (`AnmSignatureBuilder`) | Logged to `momoApiCallLog` |
| `ussd` | ANM | HTTPS, **separate client** (`ussd/lib/anm.php`) | Same provider, second implementation |
| `platform-api` | Hubtel SMS | HTTPS | |
| `ussd` | Hubtel SMS | HTTPS, **separate client** (`ussd/lib/hubtel.php`) | |
| ANM | `platform-api` | Callback → `/api/callbacks/momo` | |
| ANM | `ussd` | Callback → `ussd/callbacks/momo.php` | **Two callback endpoints for one provider** |
| `platform-api` ↔ `ussd` | — | **None** | They share a database, not an interface |

## 3. The defining problem — shared database as the integration layer

There is no contract between the two backend parts. They coordinate by writing the same rows.

The `dailyRevenueSummary` row is shared **mutable engine state**: both `GameEngineService` and
`ussd/lib/game.php` read it under `FOR UPDATE`, use it to decide whether a player is allowed to
win, and write back to it. A web player's outcome is therefore influenced by USSD play through
a code path that is maintained separately and can drift silently.

Three consequences that shape the target:

1. **Channel parity is unverifiable.** Nothing detects divergence between the two engines. The
   PRD makes parity a hard requirement (`REQ-HG-005`) and a test (`REQ-QA-010`).
2. **Ledger integrity depends on two codebases agreeing.** `REQ-ARCH-001` exists to make this
   structurally impossible; today it is convention.
3. **Global serialisation.** Every play on every channel contends for one row's lock.

## 4. Items to verify before reuse

| Item | Why it matters |
|---|---|
| Does `CallbackController::momo` verify `ANM_CALLBACK_SHARED_SECRET`? | An unverified money-moving webhook is critical (`REQ-PAY-014`) |
| Does `ussd/index.php` validate the telco gateway signature? | Without it, an arbitrary MSISDN can be asserted — total account takeover (`REQ-ID-004`, `REQ-SEC-009`) |
| Does `ussd/callbacks/momo.php` verify the same secret? | Second, independent exposure |
| Is deposit/withdrawal processing idempotent on provider retry? | Replayed callbacks must not double-credit (`REQ-PAY-013`) |

These four checks should be resolved before any of this code is carried forward, and they are
worth resolving in the current production system regardless of the refactor.

## 5. Target topology

```
Web · React Native app · USSD adapter
              │  HTTPS, /v1, Idempotency-Key
              ▼
      ┌───────────────────────┐
      │  Betplus Platform   │  identity · WALLET (sole ledger writer) · payments
      │        (PHP)          │  payout+float · tax · geo · RG · RNG · notifications
      └───────────┬───────────┘  game registry & router
                  │ Engine Contract — HTTP on loopback, mTLS
        ┌─────────┴──────────┐
        ▼                    ▼
   BlackRed engine      Heritage engine
      (PHP)               (Python)
                              │
                       Draw Partner Bridge
```

The move is from *shared database* to *explicit contract* in both directions: channels talk to
the platform over the versioned API, and engines talk to the platform over the Engine Contract.
No component other than the Wallet Service writes the ledger.

## 6. Provider migration

| Today | Target | Reuse |
|---|---|---|
| ANM mobile money | OPay Collections + Payouts | Adapter shape, call-log table, name-lookup cache. Two signature schemes replace one (HMAC-SHA512 for collections, RSA-SHA256 for payouts) |
| Hubtel SMS | Nigerian SMS provider | `smsLog`, template handling |
| Ghana USSD gateway | Nigerian aggregator, one shortcode across MTN/Airtel/Glo/9mobile | FSM structure |
| — | 5/90 draw partner | New — no analogue exists |
| — | State exclusion registry | New |
| — | NIN/BVN identity vendor | New |
