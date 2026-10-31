# Back-office dashboard refresh

## Design

The supplied dashboard reference informs the light workspace, compact sidebar, quiet borders, rounded panels, chart and summary rail, and readable tables. Betplus red is used for the banner, actions, performance chart, and metric icons, with neutral comparison series.

The shared shell and console styles cover all existing back-office screens: player details, finance, game management, promotions, content, player protection, licences, reporting, team access, audit history, and approvals. Authentication continues to use the shared auth shell from the previous change.

Player-specific destinations now sit in the player tab bar instead of repeating throughout the sidebar. The original routes remain available. Sidebar search opens the entered player ID, detail tabs retain that player, and safer-play links pass the correct player ID.

Technical details remain available when needed. Doors JSON settings and analytics field definitions are in advanced disclosures. Approval identity, before/after values, immediate-action notices, and rejection reasons remain visible at the relevant decision.

## Verification

- Browser sweep: 29 routes at 1440px and 390px, 58 checks, no page exceptions or horizontal overflow.
- Additional populated-state review: eight screens at 1440px and 320px, including deposits, staff accounts, campaigns, approvals, game settings, daily summary, audit history, and dashboard. No page exceptions or horizontal overflow.
- Opened and cancelled a game status dialog without submitting a change.
- Fixed an audit-list React key warning discovered in the first browser pass.
- Operations regression suite: 81 tests across 24 files. Initial final run had 75 passes and six failures; wording assertions were updated and a slow asynchronous table lookup received a longer explicit wait. The three affected files were rerun successfully: 13 tests passed, covering all six failures.
- Production Next build passed, including TypeScript and generation of all 75 pages.
- Scoped `git diff --check` passed.

Browser fixtures and screenshots are local, ignored artifacts under `output/operations-review/`. They do not ship as operational data. API mutations were blocked during visual review.

## Boundaries

This is a frontend rebuild. Server permissions, approval rules, wallet arithmetic, publication gates, and payment APIs are unchanged. It does not deploy the site or activate Doors real-money play.

The legacy player-ledger and player-tax routes still display the existing payment view; this change does not invent missing financial endpoints. These aliases are no longer advertised in the sidebar.
