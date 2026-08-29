# Buzzycash homepage

Static, dependency-free homepage prototype built from the platform PRD, `design.md`, and `UX-Design.md`.

## Preview

From this directory:

```powershell
python -m http.server 4173
```

Then open `http://localhost:4173`.

## Files

- `index.html` — semantic homepage content
- `styles.css` — responsive brand system and motion
- `script.js` — mobile navigation and progressive reveal behavior
- `assets/buzzycash-logo.png` — supplied logo reference copied into the project

## Motion

The homepage includes staggered hero copy, animated brand orbits, scroll-linked progress and glow, section reveals, an origin-aware mobile menu, and pointer-responsive game artwork. All non-essential motion is disabled through `prefers-reduced-motion`, and tilt effects are skipped on touch-first devices so vertical scrolling remains natural.

The `/register`, `/login`, `/help`, `/games/*`, and legal links are intended application routes. They require the future platform router/backend.
