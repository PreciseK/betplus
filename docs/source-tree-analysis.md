# Source Tree Analysis

Annotated tree of the system as it exists today. Part membership is marked `[part-id]`.

```
Betplus/
├── Betplus_PRD.md              # Target specification (§0–21) — not code
├── Betplus_Platform_PRD.md         # Earlier draft of the same target
├── OPay Payout API EN_NG_v2.9/       # Vendor documentation for the target payment rail
│   ├── OPay Payout API Developer Guide EN_NG_v2.9.md
│   ├── Payout integration process_v2.0.md
│   ├── How does a merchant generate rsa key pair.md
│   └── OPay Merchant Dashboard User Guide_Nigeria.md
│
└── BlackRed/
    ├── PRD.md                        # Superseded product doc for the current build
    └── EngineAndServices/            # ← the entire codebase, one Apache document root
        │
        ├── composer.json             # PSR-4 BlackRed\ → src/ ; 2 runtime deps
        ├── .env                      # ⚠ LIVE SECRETS committed (B-5)
        ├── .htaccess                 # Apache config — PRD targets Nginx (B-6)
        │
        ├── public/                   # ══ WEB ROOT ══
        │   ├── index.php             # [platform-api] front controller → Bootstrap\App
        │   ├── api/                  # EMPTY — routing is internal to index.php
        │   ├── app/                  # [web-app] static PWA, no build step
        │   │   ├── index.html        #   login
        │   │   ├── signUp.html       #   3-step signup wizard
        │   │   ├── dashboard.html    #   balances
        │   │   ├── play.html         #   the game screen
        │   │   ├── stakes.html       #   round history
        │   │   ├── deposit.html · withdraw.html · transactions.html · account.html
        │   │   ├── sw.js             #   service worker
        │   │   ├── manifest.json     #   PWA manifest
        │   │   ├── app.zip           # ⚠ build artefact committed
        │   │   └── __MACOSX/         # ⚠ archive junk committed
        │   ├── assets/
        │   │   ├── icons.zip         # ⚠ committed archive
        │   │   └── icons/.DS_Store   # ⚠ archive junk
        │   └── error_log             # ⚠ runtime log in web root
        │
        ├── src/                      # ══ [platform-api] PSR-4 ══
        │   ├── Bootstrap/
        │   │   ├── App.php           #   493 LOC — DI wiring + route table + dispatch
        │   │   ├── Config.php        #   .env loader with typed accessors
        │   │   └── Container.php     #   81 LOC service locator, closure factories
        │   ├── Http/                 #   hand-rolled micro-framework
        │   │   ├── Router.php        #   123 LOC, `:param` segments
        │   │   ├── Pipeline.php · Request.php · Response.php · Middleware.php
        │   │   ├── Middleware/
        │   │   │   ├── AuthMiddleware.php · RateLimitMiddleware.php
        │   │   │   ├── ErrorHandler.php · HttpException.php
        │   │   │   ├── RequestLogger.php · SecurityHeaders.php
        │   │   └── Controllers/      #   10 controllers, transport only
        │   │       ├── AuthController.php · SignupController.php
        │   │       ├── DepositController.php · WithdrawalController.php
        │   │       ├── GameController.php · StakesController.php
        │   │       ├── TransactionsController.php · MeController.php
        │   │       ├── CallbackController.php · HealthController.php
        │   ├── Wallet/               #   ⚠ business core — money + game in one namespace
        │   │   ├── GameEngineService.php   # 578 LOC — THE ENGINE (B-1, B-2)
        │   │   ├── DepositService.php      # 594 LOC
        │   │   ├── WithdrawalService.php   # 644 LOC
        │   │   ├── StakesService.php · TransactionsService.php
        │   ├── Auth/                 #   8 services: signup, OTP, session, password, rate limit
        │   ├── Integrations/
        │   │   ├── AnmClient.php · AnmSignatureBuilder.php  # Ghana mobile money
        │   │   └── HubtelSmsClient.php                      # Ghana SMS
        │   ├── Database/Connection.php     # PDO wrapper, `transactional()` helper
        │   ├── Config/SystemConfigService.php  # DB-backed runtime config
        │   ├── Sms/SmsService.php · Logging/Logger.php · Validation/Validator.php
        │   └── Support/
        │       ├── Money.php               # pesewas arithmetic
        │       └── GhanaPhone.php          # ⚠ market compiled into domain (B-7)
        │
        ├── ussd/                     # ══ [ussd] SEPARATE APPLICATION ══
        │   ├── index.php             #   237 LOC own bootstrap + FSM dispatcher
        │   ├── config.php            # ⚠ second secrets file (B-5)
        │   ├── config.example.php
        │   ├── lib/                  #   procedural, no namespace, no autoloader
        │   │   ├── game.php          # ⚠ 545 LOC — SECOND GAME ENGINE (B-3)
        │   │   ├── deposit.php · withdrawal.php   # duplicate money paths
        │   │   ├── db.php · session.php · player.php · states.php
        │   │   ├── anm.php · hubtel.php · msisdn.php · password.php · log.php · response.php
        │   ├── states/               #   23 FSM states, render_/handle_ function pairs
        │   │   ├── EntryState.php · MainMenuState.php · UnregisteredMenuState.php
        │   │   ├── Reg*State.php (4)         # registration
        │   │   ├── Play*State.php (5)        # the game
        │   │   ├── Dep*State.php (2)         # deposits
        │   │   ├── Wd*State.php (7)          # withdrawals, two destinations
        │   │   └── BalanceState.php · LastStakeState.php
        │   ├── migrations/           # ⚠ third migration location (008, 009)
        │   ├── callbacks/momo.php    #   mobile-money webhook for USSD flows
        │   ├── workers/cleanup.php   #   session reaper (cron)
        │   ├── tests/run.php         #   the only test runner that exists
        │   └── ussdDeploy.zip        # ⚠ committed deployment artefact
        │
        ├── migrations/               # ══ INCOMPLETE (B-4) ══
        │   ├── 001_initial_schema.sql      # 1142 LOC, 26 tables
        │   ├── 002_phase2_auth.sql         # 177 LOC, authtoken
        │   └── test_only_authtoken.sql
        │
        ├── config/                   # EMPTY
        ├── workers/                  # EMPTY — no queue exists
        ├── logs/app.log              #   Monolog output
        └── vendor/                   #   Composer dependencies
```

## Critical folders

| Folder | Why it matters to the refactor |
|---|---|
| `src/Wallet/` | Holds both the ledger and the game engine in one namespace. The PRD requires these to be separate services with the wallet as sole ledger writer. This is the primary decomposition target. |
| `ussd/lib/` | A parallel implementation of everything in `src/Wallet/`. Either it collapses into a channel adapter over the platform API, or the platform inherits two divergent money paths. |
| `migrations/` | The source of truth for schema is currently *the running production database*, not this folder. Reconstructing it is a prerequisite for a staging environment. |
| `src/Http/` | The hand-rolled framework is small, readable and works. It is a reasonable base to keep; the decision is whether the target adopts a framework instead. |
| `src/Integrations/` | ANM and Hubtel are replaced wholesale by OPay and a Nigerian SMS provider, but the adapter shape (client + signature builder + call log) is the right shape and is worth preserving. |

## Entry points

| Path | Type | Bootstrap |
|---|---|---|
| `public/index.php` | HTTP JSON API | `BlackRed\Bootstrap\App::run()` |
| `ussd/index.php` | HTTP webhook | inline `require` of `lib/*.php` |
| `ussd/callbacks/momo.php` | HTTP webhook | inline |
| `ussd/workers/cleanup.php` | cron | inline |

## Committed artefacts that should not be in a repository

`.env`, `ussd/config.php`, `public/app/app.zip`, `public/assets/icons.zip`,
`ussd/ussdDeploy.zip`, `public/app/__MACOSX/`, `public/assets/__MACOSX/`, `.DS_Store`,
`public/error_log`, `ussd/error_log`, `logs/app.log`, `vendor/`.

There is no `.gitignore` at the project root — only `ussd/.gitignore`.
