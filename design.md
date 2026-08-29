# Betplus Design System

> Status: foundation specification for product design and implementation  
> Applies to: responsive Web, the React Native app (Android at v1; iOS-ready), transactional messages, and the shared visual shell around USSD content  
> Product source of truth: [`Betplus_Platform_PRD.md`](./Betplus_Platform_PRD.md)  
> UX behavior and journeys: [`UX-Design.md`](./UX-Design.md)

## 1. Purpose

This document converts the supplied Betplus logo and the platform PRD into a practical visual system. It is a contract for designers, engineers, content authors, and AI coding tools—not a loose mood board.

Betplus is the platform. BlackRed and Heritage are games inside it. The product must always feel like one trusted account, one wallet, and one compliance perimeter, even when the games have different personalities.

The design goal is **warm energy with financial clarity**. It should feel quick and optimistic without looking reckless, childish, casino-loud, or like a generic fintech template.

## 2. Brand foundation

### 2.1 What the supplied logo communicates

The mark combines a rounded currency-like monogram with a coral-to-amber gradient. Its continuous geometry suggests movement and exchange; the open forms keep it approachable. The black field and bold white wordmark add weight and strong recognition at small sizes.

The observed raster colors are approximately:

- Coral: `#F44734`
- Orange: `#F47732`
- Amber: `#F9B83A`
- Ink: `#000000`
- Wordmark: `#FFFFFF`

These are visual observations from the supplied PNG, not a substitute for a future vector brand master.

### 2.2 Brand attributes

| Attribute | Express it through | Do not turn it into |
|---|---|---|
| Energetic | warm accent, decisive hierarchy, responsive feedback | flashing surfaces, urgency, countdown pressure |
| Trustworthy | explicit money states, receipts, stable layouts, plain copy | badge clutter, fake testimonials, vague “secure” claims |
| Accessible | high contrast, large controls, text plus icons, low-bandwidth paths | color-only outcomes, tiny legal copy, motion-dependent meaning |
| Nigerian | Naira-first formatting, WAT, local language support, culturally reviewed art | flag motifs, stereotypes, generic “African” patterns |
| Fair | visible odds, tax breakdowns, deterministic receipts, neutral loss states | near-miss hype, concealed deductions, celebration of losses |
| Human | blame-free copy, warm surfaces, clear recovery paths | jokes during money errors, mascot chatter, forced delight |

### 2.3 Brand architecture

- **Betplus** owns identity, wallet, payments, activity, support, settings, responsible-play tools, and all platform navigation.
- **BlackRed** and **Heritage** may introduce game-specific imagery and interaction patterns only inside a clearly bounded game space.
- Platform components keep the Betplus typography, spacing, status colors, wallet language, and receipt pattern.
- A game may never recolor platform success, warning, error, money, or compliance states.
- Cross-game surfaces show game identity with a title, icon, and one restrained accent—not a complete shell replacement.
- A third game must fit the same shell without changing global navigation or wallet components.

## 3. Logo system

### 3.1 Required assets

The supplied PNG is suitable as a reference only. Before production, create and approve:

- Full lockup: mark plus “Betplus” wordmark, light-on-dark
- Full lockup: dark wordmark for light surfaces
- Mark-only SVG
- Single-color light and dark marks
- App icon and maskable icon, optically adjusted rather than merely scaled
- Favicons at 16, 32, and 48 px
- Social preview lockup

Keep the approved vector masters in a platform-level asset directory, not under a game directory. Do not trace or regenerate the logo with AI for production.

### 3.2 Usage

- Clear space: at least the width of the mark’s main stroke on all sides.
- Minimum full-lockup width: 120 px digital. Below that, use the mark only.
- Place the gradient logo on `ink-950`, white, or quiet solid surfaces with sufficient separation.
- Prefer the single-color mark when the gradient would be illegible, expensive, or inconsistent, including USSD-adjacent monochrome material and very small icons.
- The header logo links to Home. It is not decorative and needs the accessible name “Betplus home.”

Never stretch, rotate, add a drop shadow, recolor individual segments, place the logo inside a generic rounded-square badge, or use the mark as a repeated background pattern.

## 4. Color system

### 4.1 Primitive tokens

```css
:root {
  --bzc-coral-500: #F44734;
  --bzc-orange-500: #F47732;
  --bzc-amber-400: #F9B83A;
  --bzc-coral-700: #D93626;
  --bzc-coral-800: #B42318;

  --bzc-ink-950: #11100E;
  --bzc-ink-800: #2A2622;
  --bzc-stone-700: #675F57;
  --bzc-stone-300: #D8D0C7;
  --bzc-stone-150: #EAE4DD;
  --bzc-stone-100: #F2EEE8;
  --bzc-canvas: #FAF8F4;
  --bzc-white: #FFFFFF;

  --bzc-green-700: #0F7B4D;
  --bzc-blue-700: #2457A7;
  --bzc-amber-800: #8A4B08;
  --bzc-red-800: #B42318;
}
```

The logo gradient is:

```css
linear-gradient(105deg, #F44734 0%, #F47732 56%, #F9B83A 100%)
```

Use it for the logo, a short brand rule, selected illustration accents, or a large non-text feature area. Do not use gradient text, gradient borders on every card, or the gradient as a generic button fill. Functional controls need a stable solid color in every state.

### 4.2 Semantic tokens

| Token | Light theme | Dark theme | Purpose |
|---|---:|---:|---|
| `surface.canvas` | `#FAF8F4` | `#11100E` | app/page background |
| `surface.base` | `#FFFFFF` | `#1B1916` | main content surface |
| `surface.subtle` | `#F2EEE8` | `#2A2622` | grouped secondary content |
| `text.primary` | `#11100E` | `#FAF8F4` | primary copy |
| `text.secondary` | `#675F57` | `#D8D0C7` | supporting copy |
| `border.default` | `#D8D0C7` | `#49423B` | component boundaries |
| `action.primary` | `#D93626` | `#F9B83A` | principal action |
| `action.primaryText` | `#FFFFFF` | `#11100E` | action label |
| `focus.ring` | `#B42318` | `#F9B83A` | keyboard focus |
| `feedback.success` | `#0F7B4D` | lighter tested green | completed money/action state |
| `feedback.info` | `#2457A7` | lighter tested blue | neutral information |
| `feedback.warning` | `#8A4B08` | `#F9B83A` | attention or pending risk |
| `feedback.error` | `#B42318` | lighter tested red | failure/destructive state |

Rules:

- `#F44734` does **not** meet 4.5:1 against white for normal text. Use `#D93626` with white for solid light-theme actions, or use ink text on the bright logo colors.
- Use color with a status icon and explicit label: “Paid,” “Pending,” “Failed.”
- Black and red predictions must also use letters, card symbols, borders, or patterns. Color alone is prohibited.
- Never reuse success green for winnings hype or red for losses in a way that confuses system status.
- Verify every final component state with a contrast checker; tokens do not remove the need to test combinations.

### 4.3 Color distribution

Use warm off-white or near-black for roughly 60% of a screen, neutral surfaces for 30%, and brand/status color for no more than 10%. Heritage artwork may be richer inside the board, but surrounding controls remain calm.

## 5. Typography

### 5.1 Families

- **Display:** Outfit, 600–800, for short product and game headings.
- **UI/body:** Sora, 400–700, for labels, body copy, buttons, and data.
- **Fallback:** `ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif`.

This pairing aligns with the current inherited interface while fitting the rounded geometry of the logo. Self-host compressed WOFF2 files, load only required axes/weights, use `font-display: swap`, and include Latin Extended glyphs. Test Yoruba and Igbo diacritics before release; use the fallback stack if the chosen subset is incomplete.

Do not use a decorative display face for balances, odds, tax, terms, form inputs, or result details.

### 5.2 Scale

| Role | Mobile | Desktop | Weight | Line height |
|---|---:|---:|---:|---:|
| Display | `clamp(2rem, 7vw, 3.5rem)` | same | 750 | 1.05 |
| H1 | 32 px | 40 px | 700 | 1.15 |
| H2 | 26 px | 32 px | 700 | 1.2 |
| H3 | 21 px | 24 px | 650 | 1.25 |
| Body | 16 px | 16 px | 400 | 1.5 |
| Body strong | 16 px | 16 px | 650 | 1.45 |
| Small | 14 px | 14 px | 400 | 1.45 |
| Caption | 12 px | 12 px | 550 | 1.4 |
| Money hero | 30 px | 36 px | 700 | 1.1 |

- Body copy is never below 16 px; 12–14 px is only for short metadata.
- Use sentence case. Avoid all caps except compact, non-essential labels under 15 characters.
- Use `font-variant-numeric: tabular-nums` for balances, amounts, times, odds, and tables.
- Keep prose to 50–75 characters per line.

## 6. Layout and spatial system

### 6.1 Spacing tokens

Use a 4 px base with a deliberately small scale: `4, 8, 12, 16, 24, 32, 48, 64`.

- Screen gutter: 16 px mobile, 24 px tablet, 32 px desktop.
- Component internal padding: 16 px compact, 24 px standard.
- Section gap: 32 px mobile, 48 px desktop.
- Page and application shells always span the full viewport width and use at least `100svh`; never center the product inside an outer desktop frame.
- Constrain only inner reading, form, receipt, or deliberately focused work areas. General player content may use 1120 px as an inner measure, not as a page-shell maximum.
- Reading/form column: 560–640 px.
- Back-office and game canvases are full width. Their navigation, headers, backgrounds, dividers, and session surfaces must reach the relevant viewport edges.

Do not put every paragraph in a card. Use cards only for grouping, selection, actionable objects, or content that needs a surface boundary.

### 6.2 Responsive structure

- Design mobile first from 320 px upward; verify at 320, 360, 390, 768, 1024, and 1440 px.
- Mobile app/Web: fixed bottom navigation with safe-area padding; maximum five destinations.
- Desktop: stable left navigation or top-level product navigation, never a hamburger-only shell.
- Use a 4-column mobile, 8-column tablet, and 12-column desktop grid.
- Preserve the primary action in a thumb-friendly lower zone when the action is safe and reversible.
- Never obscure form controls or confirmation details with a sticky CTA or virtual keyboard.

### 6.3 Back-office dashboard composition

The back office uses a warm-light workspace with a persistent deep-ink sidebar. This theme stays stable regardless of the player-facing colour mode so operational screenshots, training and support references remain consistent. Betplus coral is used for the current destination, primary actions and items that need attention; green is not a dashboard brand colour.

The overview follows one predictable vertical rhythm:

1. Page title: **Today at a Glance**, selected data date and last-updated time.
2. One continuous three-to-five KPI strip with a large figure and one comparison per segment.
3. One primary seven-day chart plus a compact Player safety summary.
4. Needs Attention and Recent Activity side by side on desktop, stacked on mobile.

The persistent header includes one game selector sourced from the game registry. **All games**, **BlackRed**, and **Heritage** are the initial options; adding a registered game adds one option without changing the shell. A selected game scopes the dashboard figures, chart, queues, activity and links together, and remains selected while the operator moves between back-office pages.

KPI segments share one surface and use internal dividers; do not turn them back into a decorative card mosaic or show more than five above the fold. The overview date control supports Today, Previous day and direct access to any permitted historical date. Keep charts to one dominant visual per page; exact values remain available through an accessible table disclosure.

Desktop navigation shows at most seven first-level groups: Dashboard, Customers, Finance, Product, Compliance, Insights and Administration. Each group contains small named sub-management sets. Customer → Player details contains Current case, Ledger, Tickets, Payments, Tax, Notifications and Responsible play. The player search, identity summary and balances remain stable context; only the selected evidence surface renders. One destination should answer one operator question, and no inner sidebar should reproduce these destinations. Mobile uses one Menu disclosure rather than reproducing the full desktop rail.

## 7. Shape, elevation, and iconography

### 7.1 Shape

The logo is rounded, but the entire UI must not become pill-shaped.

- Controls: 10–12 px radius.
- Cards/panels: 16 px radius.
- Large feature surfaces: 20–24 px radius.
- Pills: only tags, filters, statuses, or compact segmented controls.
- Game tiles may use one distinctive clipped or arched edge derived from the mark; do not repeat it on all components.

### 7.2 Elevation

- Default boundaries use contrast and a 1 px border.
- Use a low shadow only for raised menus, drawers, sticky controls, and dialogs.
- No glow around money, CTA buttons, or wins.
- On dark surfaces, elevation comes from lighter surfaces and borders, not heavy black shadows.

### 7.3 Icons

- Use one rounded-outline icon family with 1.75–2 px stroke.
- Default sizes: 20 px inline, 24 px navigation, 32 px empty state.
- Pair unfamiliar icons with text. Icon-only actions require an accessible name and tooltip on hover/focus.
- Do not use emoji as product icons or status indicators.
- Currency, wallet, tax, ticket, limits, support, and game icons must be semantically distinct.

## 8. Imagery and cultural art

- Prefer commissioned illustration, product UI, and culturally reviewed Heritage artwork over generic stock photography.
- If people are shown, represent contemporary Nigerian users without tokenism and with varied age, gender, ability, region, and device context.
- Do not use currency rain, gold coins, luxury cars, champagne, jackpot explosions, or “winner lifestyle” imagery.
- Heritage regalia is not decorative clip art. Every depicted item requires catalogue integrity and named cultural review under `REQ-HG-060`–`REQ-HG-067`.
- Do not infer or visually assign a user’s ethnic tradition from name, location, or behavior.
- Images need meaningful alt text unless purely decorative.

## 9. Motion and sound

Motion explains state; it does not intensify gambling.

| Token | Duration | Use |
|---|---:|---|
| `motion.instant` | 80–100 ms | pressed feedback |
| `motion.fast` | 140–180 ms | hover, focus-adjacent transitions |
| `motion.base` | 220–280 ms | drawer, disclosure, state change |
| `motion.reveal` | 450–700 ms | one meaningful game reveal step |

- Use deceleration on entry and acceleration on exit.
- Avoid bounce, slot-machine easing, flashing, shake-to-chase, fake suspense delays, or rapidly pulsing CTAs.
- The reveal never changes or implies influence over a predetermined outcome.
- Reduced-motion mode skips sequential reveals and shows the complete textual result immediately.
- No autoplay sound. Optional sound and haptics must be user-controlled and never carry essential information.
- Never celebrate a loss or highlight a near miss.

## 10. Core component language

Every component ships with default, hover, pressed, focus-visible, disabled, loading, success, and error states as applicable.

### Buttons

- One visually dominant action per region.
- Labels describe the outcome: “Confirm ₦1,000 stake,” “Withdraw ₦5,000,” “Try again.”
- Never disable without nearby explanatory text.
- Destructive and irreversible actions require a specific consequence statement.
- Minimum target: 44×44 CSS px Web; 48×48 dp native.

### Form controls

- Persistent visible labels; placeholders are examples, not labels.
- Helper/error text sits with the field and uses text plus an icon.
- Monetary inputs show `₦` outside the editable numeral and repeat the formatted amount in confirmation.
- Validate on blur or submit, not on every keystroke.
- OTP uses one logical input even if visually segmented, supporting paste and autofill.

### Money and balance components

- Always show the currency symbol and two decimals only where precision matters; user-facing whole Naira may omit `.00` consistently.
- Distinguish **Play Balance** and **Winnings Balance** with titles and explanations, not just color.
- Transaction rows include type, game/provider, timestamp in WAT, signed amount, and status.
- Win receipts show gross prize, withholding tax, net credited, destination/status, ticket reference, and help route.
- Pending money states persist in Activity; do not communicate them only by toast.

### Feedback

- Toast: reversible low-risk confirmation; 4–8 seconds, pausable, never the only record of a money event.
- Banner: persistent connectivity, provider, licence, or registry status.
- Inline message: field or local component issue.
- Dialog: one focused confirmation or blocking choice; not long forms.
- Full page: KYC, responsible-play controls, multi-step funding/withdrawal, detailed receipts.

### Empty and loading states

- Empty states explain what the section is, why it is empty, and one next action.
- Use skeletons only when the final structure is known; do not skeleton a balance that might be mistaken for zero.
- A hidden balance is `••••`, loading is “Loading balance…,” and unavailable is “Balance unavailable.” These are different states.

## 11. Game visual boundaries

### BlackRed

- High-contrast, restrained, and fast to scan.
- Black and red selections use `B`/`R`, text labels, and distinct shapes or patterns in addition to color.
- Show true odds and potential return before confirmation.
- A loss result is factual and quiet. “Play again” cannot be the dominant loss-screen action.
- Velocity and reality-check UI must interrupt cleanly without shame or visual resistance.

### Heritage

- Richness belongs in the culturally reviewed board and regalia, not the platform chrome.
- The 3×3 board uses numbered positions, visible selected state, and a persistent “5 selected” counter.
- Revealed items show canonical/local name and one line of cultural context.
- At settlement, all nine positions and the complete winning set remain inspectable.
- Unpicked winning positions are factual and visually equal to other revealed information—no “so close” treatment.

## 12. Accessibility baseline

Target WCAG 2.2 AA, which satisfies the PRD’s WCAG 2.1 AA minimum.

- Logical heading order, landmarks, and one page `h1`.
- Full keyboard use with clearly visible `:focus-visible` states.
- DOM order matches visual order at every breakpoint.
- Touch targets and control spacing meet the minimums above.
- Text contrast ≥4.5:1; large text and UI graphics ≥3:1.
- 200% text zoom and 400% browser zoom do not hide content or actions.
- Screen reader announcements are restrained: status changes, validation summary, result completion, and money completion; not every animation frame.
- Outcomes, timers, progress, and errors never rely only on color, position, animation, vibration, or sound.
- Support reduced motion, high contrast, screen magnification, and device font scaling.

## 13. Anti-slop rules

A screen fails design review if it contains any of the following without a documented functional reason:

- A generic hero with a huge slogan, floating phone mockup, and decorative gradient orbs
- Multiple competing gradients, glass panels, neon glow, blur, or “premium” dark-mode clichés
- A dashboard made entirely of interchangeable rounded cards
- More than one primary CTA in the same decision region
- Pill-shaped containers used for ordinary buttons, cards, inputs, and navigation simultaneously
- Random emoji, 3D coins, confetti, lightning bolts, rockets, or trophy imagery
- Fake balances, fake winners, fake urgency, fake live counters, or unsupported trust badges
- Marketing copy where the user needs an amount, status, odds, fee, tax, or next step
- Oversized headings that push the actual task below the fold
- Low-contrast gray text presented as sophistication
- Motion that delays money confirmation or manufactures suspense
- A game skin that replaces platform navigation or changes financial terminology
- A beautiful happy path with no loading, empty, error, pending, blocked, or recovery states

The test is not “Does this look modern?” It is: **Can a player understand what happened to their money, what is required next, and how to recover—quickly, on a small device, in poor network conditions?**

## 14. Design review checklist

Before handoff, attach evidence for:

- [ ] User goal and primary action are stated.
- [ ] Content hierarchy works in grayscale and without imagery.
- [ ] Every monetary amount has currency, meaning, and state.
- [ ] Odds, tax, fees, and outcome timing are disclosed before commitment.
- [ ] Loading, empty, error, pending, blocked, offline, and success states exist.
- [ ] Keyboard, screen reader, 200% text, and reduced-motion paths were tested.
- [ ] Contrast and touch-target checks pass.
- [ ] English and Nigerian Pidgin fit; layouts tolerate 40% expansion.
- [ ] Yoruba/Igbo diacritics render where relevant.
- [ ] No dark pattern or near-miss amplification is present.
- [ ] Game-specific art has required cultural review.
- [ ] A third game could reuse the platform shell unchanged.
- [ ] The implementation uses tokens and shared components rather than local magic values.

## 15. Governance

- Primitive → semantic → component tokens are the required hierarchy.
- Token names describe purpose, not a screen or temporary appearance.
- Shared component changes require screenshots at mobile and desktop widths, state coverage, accessibility notes, and migration impact.
- Game-only tokens live under a game namespace and cannot override platform financial/status semantics.
- Breaking token or component changes are versioned and documented.
- Designers and engineers review production behavior together; a static mockup is not final evidence.
- Any deliberate exception to this document must name the user benefit, affected surfaces, accessibility impact, and owner.
