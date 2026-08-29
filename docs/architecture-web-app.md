# Architecture — Web App (`web-app`)

**Root:** `BlackRed/EngineAndServices/public/app/`
**Type:** web · **Runtime:** static files served by Apache · **Build:** none

---

## 1. Executive summary

Ten hand-written HTML pages plus a service worker and a PWA manifest, served directly from the
document root. No framework, no bundler, no package manifest, no build step, no dependency on
Node. Each page is a full document that calls the JSON API directly.

## 2. Pages

| File | Purpose |
|---|---|
| `index.html` | Login |
| `signUp.html` | Three-step registration wizard (lookup → OTP → complete) |
| `dashboard.html` | Play and payout balances |
| `play.html` | The game — deck lock, colour picks, stake, result |
| `stakes.html` | Round history |
| `deposit.html` | Mobile-money deposit |
| `withdraw.html` | Withdrawal, two destinations |
| `transactions.html` | Unified deposit + withdrawal feed |
| `account.html` | Profile and password change |
| `sw.js` | Service worker |
| `manifest.json` | PWA manifest |

## 3. Assessment

**Consequences of no build step:** no module system, no shared component layer, no design
tokens, no type checking, no bundle budget, no way to enforce the PRD's accessibility
requirements systematically. Markup and behaviour are duplicated across ten documents.

**What it does get right:** it ships as a PWA with a service worker, it has no supply chain, it
loads fast, and it is trivially deployable to a cPanel document root.

## 4. Distance from the PRD

| PRD requirement | Current |
|---|---|
| React Native app, Android + iOS from one codebase | Does not exist |
| Responsive web as a distinct channel | These ten pages |
| `REQ-NFR-007` FCP ≤ 2.5 s on 3G | Plausibly met — nothing to measure it with |
| `REQ-NFR-050` WCAG 2.1 AA | No audit, no tooling, no focus/contrast discipline |
| `REQ-NFR-052` all strings externalised, English + Pidgin | Strings inline in markup |
| `REQ-NFR-054` `prefers-reduced-motion` | Not implemented |
| `REQ-BR-022` colour never the sole carrier of meaning | **The game is entirely a red/black distinction** — highest-priority accessibility item |
| `REQ-RG-*` reality checks, limits, net position, break | No surface exists |
| `REQ-TKT-010` outcome-determined-at-purchase disclosure | Not present |

## 5. Target disposition

The web app is a rewrite, not a refactor. It carries no shared code with the target and no
structure worth migrating. What is worth extracting before it is replaced:

- The **screen inventory and flow** — it is a working map of every journey the product needs.
- The **signup wizard sequencing**, which matches the PRD's OPay-assisted registration.
- The **play screen interaction model** — deck lock, pick, stake, reveal.

Treat the existing pages as a functional specification and reference implementation for the
new web and React Native clients, then delete them.

## 6. Housekeeping

`public/app/` contains `app.zip` and a `__MACOSX/` directory; `public/assets/` contains
`icons.zip` and a `.DS_Store`. These are archive artefacts committed by accident and should
not be carried into the new repository.
