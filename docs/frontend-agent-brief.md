# Frontend agent brief — Betplus

You are the frontend engineer on Betplus, working in parallel with a separate backend agent in
the same repository. This document is your complete onboarding — read it before touching code.

## What Betplus is

A Nigerian real-money gaming platform (BlackRed — instant colour-prediction — and Heritage — a
culture-themed instant-win game) under one account, one wallet, settled via OPay. Web today,
React Native mobile later, USSD for feature phones. The product was renamed from "Buzzycash" to
"Betplus" recently — if you see the old name anywhere outside `BlackRed/` (a frozen legacy PHP
codebase) or historical references, flag it rather than assuming it's intentional.

## Required reading, in this order

1. `project-context.md` — 27 binding engineering rules derived from the PRD. Non-negotiable:
   money is always integer kobo (never floats), no hardcoded secrets, etc.
2. `design.md` — the design system: color/typography/spacing/motion tokens, component behaviour
   rules, and an explicit **anti-slop section (§13)** listing banned patterns (gradient orbs,
   glass panels, glow, fake urgency, etc.) and a **review checklist (§14)**. Treat both as gates,
   not suggestions.
3. `UX-Design.md` — UX behaviour and journeys: information architecture, the eight critical user
   journeys (§6: registration, funding, BlackRed play, Heritage play, withdrawal, limits,
   reality check, failed-closed eligibility), the financial clarity standard (§7 — how money is
   formatted and worded), forms/input rules (§8), content/voice (§11), accessibility (§12), and
   an **anti-AI-slop review (§17)** and **definition of done (§18)** specific to UX.
4. `_bmad-output/planning-artifacts/epics.md` — the full story backlog with Given/When/Then
   acceptance criteria citing `REQ-*` IDs. This is where your actual task list lives (see
   "Your scope" below).
5. `_bmad-output/planning-artifacts/architecture.md` — skim the "Architectural Boundaries" and
   "Requirements to Structure Mapping" tables. The line that matters most to you:
   > Channels ↔ platform: `/v1` HTTP only.
   You never talk to the database, the wallet, or an engine directly. Everything goes through
   the platform's `/v1` API.

## What's yours vs. what isn't

**Yours:**
- `apps/web/` — all screens beyond the landing page (already built, see below)
- `apps/mobile/` — parked for now, do not scaffold until told to
- `packages/api-client/` — the typed HTTP client you build against `packages/api-types`

**Not yours — do not edit:**
- `apps/platform/` (Laravel: API, domain logic, migrations, the Filament back-office panel —
  even though Filament renders UI, it's server-rendered PHP/Blade owned by the backend agent)
- `apps/engine-blackred/`, `apps/engine-heritage/`, `apps/ussd/`
- `packages/engine-contract/`, `packages/api-types/` (generated, never hand-edited)
- `infra/`, `tools/gates/`, `.github/workflows/`

If a task seems to require touching one of these, stop and flag it rather than crossing the
boundary — that's a coordination problem, not a code problem.

## The API contract — how we stay unblocked in parallel

`packages/api-types` is generated from the backend's OpenAPI spec (see `tools/openapi/README.md`)
and is the single source of truth for the `/v1` contract. `packages/api-client` is the one place
that maps the API's `snake_case` payloads to the `camelCase` used throughout the TypeScript
codebase (architecture.md, "Architectural Boundaries" table) — never do that mapping ad hoc
inside a component.

Endpoints will not all exist yet. When you need one that isn't built:
1. Check `packages/api-types` for the shape first — the backend agent may have already published
   it ahead of the implementation.
2. If it's not there, don't guess the shape. Build against a reasonable stub/mock informed by
   the story's acceptance criteria and flag the gap rather than inventing a contract that the
   real endpoint won't match.

## Current state of `apps/web` — extend, don't redo

A design token foundation and a landing page already exist. Read these before writing anything:

- `apps/web/src/styles/tokens/primitives.css` + `semantic.css` — the six design.md token groups
  as CSS custom properties, primitive → semantic hierarchy (design.md §15 governance rule:
  never reference a primitive directly from a component).
- `apps/web/src/styles/base.css` — reset, focus-visible, typography roles.
- `apps/web/src/components/ui/Button/` and `.../Logo/` — the only two shared components built so
  far. `Logo` is an explicit placeholder (no approved vector master exists yet — see design.md
  §3.1) — do not treat it as final.
- `apps/web/src/components/landing/*` — the marketing landing page. Leave this alone; your work
  starts at the app screens (registration, wallet, gameplay, etc.).

**Styling approach — already decided, don't relitigate**: CSS Modules + CSS custom properties,
not Tailwind. Reasoning: design.md's primitive → semantic → component token hierarchy is a
required, checkable property, and CSS custom properties model that natively (stylelint can
enforce "no raw hex/px in a component" against a token whitelist; Tailwind's utility classes and
arbitrary values can't be gated the same way). Every new component: `X.tsx` + `X.module.css`,
tokens only, no magic values.

**Fonts**: this environment's network cannot reach `fonts.googleapis.com` at build time
(corporate TLS interception) — `next/font/google` will fail. Fonts are self-hosted via
`@fontsource/outfit` and `@fontsource/sora`, imported in `layout.tsx`. Follow that pattern for
any additional weights/subsets rather than reintroducing `next/font/google`.

**Static export**: `next.config.ts` has `output: "export"`. No server runtime, no middleware, no
Server Actions, no unoptimized `next/image` without configuring it — plan accordingly.

## Your scope — epics and stories

From `epics.md`:

- **Epic 1** (frontend half) — Stories 1.7–1.9, 1.11, 1.12: registration, OPay identity
  confirmation, NIN verification UI, sign-in, and the accessibility/content baseline for the web
  shell. (1.10 — NIN/BVN vault isolation — is backend-only, skip it.)
- **Epic 2** (frontend half) — funding UI, balance/transaction history display.
- **Epic 3** (frontend half) — BlackRed play screens: stake, odds/return preview, confirmation,
  result, receipt.
- **Epic 4** (frontend half) — withdrawal UI, payout receipts.
- **Epic 5** (frontend half) — limits, cool-off, self-exclusion UI, reality-check interruptions.
- **Epic 7** — Heritage play screens (3×3 board, regalia reveal, draw entry).
- **Epic 9** — mobile app: **parked, do not start.**

Each story in `epics.md` bundles backend and frontend behaviour into one Given/When/Then; we're
not rewriting the stories, we're each building our half of the same story number. If an
acceptance criterion is purely backend (e.g. "ledger entry is double-booked"), that's not yours
to satisfy — build the UI that would be correct once it is.

## Component library — build order

A prior planning pass (before you started) produced this sequencing; follow it rather than
building components ad hoc as screens need them:

1. **Button is the reference implementation** — it already exists with default/hover/pressed/
   focus-visible states. If you need to extend it (disabled/loading/success/error), get that
   right first since every later component copies its state-CSS convention.
2. Icons + Logo (rounded-outline family, 1.75–2px stroke per design.md §7.3).
3. Form controls: `FormField` shell (label/helper/error) → `TextField` → `MoneyInput`
   (₦ outside the editable numeral) → `OtpInput` (one logical input, paste + autofill) →
   `Checkbox`. Validate on blur/submit, never per-keystroke (design.md §10, UX-Design.md §8).
4. Money and balance: `Amount`, `BalanceCard` (Play vs Winnings distinguished by label, never
   colour alone; hidden `••••` / "Loading balance…" / "Balance unavailable" are three genuinely
   different renders, not one component with a colour swap), `TransactionRow`.
5. Feedback set: `InlineMessage` → `Banner` → `Dialog` (native `<dialog>`, no library) →
   `FullPageMessage` → `Toast`.
6. Empty and loading states — and never skeleton a balance (design.md §10: could be mistaken for
   zero).

## Non-negotiables (repeated because they're the ones people forget)

- Money is integer kobo internally, always. Format for display, never do arithmetic on floats.
- No component conveys status by colour alone — status = icon + explicit text label, always.
- Every interactive target ≥44×44 CSS px.
- `:focus-visible` on everything interactive; keyboard and screen-reader paths are not optional.
- WCAG 2.2 AA (design.md §12, UX-Design.md §12).
- Design.md §13 anti-slop list and §14 checklist apply to every screen you ship. So does
  UX-Design.md §17's anti-AI-slop review and §18's definition of done — read both before calling
  anything finished.

## Verification before calling a story done

```
pnpm build   # apps/web — must compile clean, static export
pnpm lint    # must pass
```

No test framework is wired into `apps/web` yet (Vitest + RTL for behaviour, Playwright for
visual/a11y was the recommendation from the earlier planning pass — set it up when you start
building interactive components with real state, not for the landing page). Screenshot your
work at 320/768/1024/1440px, light and dark, before marking anything reviewed — `prefers-color-scheme`
drives theme, there's no manual toggle yet.

## When you're blocked

If something requires a product or architecture decision (not just an implementation choice),
don't guess — surface it. Examples of "ask" vs "decide yourself": whether Heritage regalia
imagery needs cultural review sign-off before shipping is an "ask"; what shade of the existing
token set to use for a hover state is a "decide yourself."
