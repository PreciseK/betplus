# Doors: playable 3D vault and Big Man win celebration

## Current round rules

- Display all stakes, balances and rewards in naira (NGN). The local balance remains simulated.
- Up to five attempts within the 10-second countdown. The first treasure immediately ends and settles the round.
- Gold bars: 3x stake; treasure chest: 5x stake; multiplier card: 2x stake.
- Five empty doors or timer expiry end the round without a reward. Prizes do not accumulate across picks.
- The supplied Big Man character, edited into a transparent thumbs-up pose, appears beside a winnings dialog after a successful reveal.

## Review

- Game: http://localhost:3100/doors-demo/
- No-stake asset workshop: http://localhost:3100/doors-demo/workshop/
- Editable Blender library: `tools/doors/vault-assets.blend`
- Rebuild script: `tools/doors/build_vault.py`
- Big Man Blender source: `tools/doors/big-man.blend`
- Big Man rebuild script: `tools/doors/build_big_man.py`
- Big Man runtime model: `apps/web/public/assets/doors/models/big-man.glb`
- Rendered portrait fallback: `apps/web/public/assets/doors/big-man-portrait.png`
- Runtime models: `apps/web/public/assets/doors/models/`

Built and exported with the installed Blender 5.2.2 LTS. No Blender installation is needed to play the exported browser scene.

## Delivered

- Reusable steel/brass compartment, a left hinge, lock rotor and two retracting bolts.
- Gold bars and stacked coins; a treasure chest with a hinged lid and coins; an emerald multiplier plaque. Multiplier lettering is created from the actual round value in the browser.
- Four GLB files, about 612 KiB combined, including animation clips and materials.
- Three.js renderer reuses the compartment across twenty positions, batches rigid geometry by material, caps pixel ratio at 1.5, and stops requesting frames after reveal animations finish.
- HTML buttons remain the keyboard/touch targets; the standalone demo uses React state; the connected account route renders server-confirmed rounds and settlement.
- Skippable/replayable introduction from the user's ten-second cinematic. Browser-compatible fast-start MP4 and a still poster are copied into the project. Sound starts on and can be muted. No countdown or stake debit occurs during the intro or while the model loader is preparing the board.
- Manual illustrated mode plus automatic fallback on renderer initialization failure, model-load failure or WebGL context loss.

## Rebuild

From the repository root in PowerShell:

```powershell
& 'C:\Program Files\Blender Foundation\Blender 5.2\blender.exe' --background --python 'tools/doors/build_vault.py'
```

The `.blend` library arranges the four asset groups side by side for inspection. Frame 1 is closed; frame 24 is open. Export is performed with each asset at the origin before the library is arranged. All coordinates, materials and animations can be edited in the script or Blender source. Rebuilding replaces these generated outputs.

## Scope and validation

This is a functional 3D prototype with simple materials. The win dialog now uses the transparent thumbs-up image derived from the user-supplied Big Man reference. The earlier Blender host model is retained as source history and is not used in the popup. The corridor is a still backdrop, not a navigable 3D room. The intro-to-game transition is a direct handoff, not a matched-camera continuous scene. The separate `/games/doors/` route is now wired to the platform API and server RNG; see `docs/doors-backend-integration.md`. Real-money activation remains disabled by default.

Browser checks exercised cinematic playback/completion/skip, unchanged pre-game timer, all three treasure types, the five-attempt cap, immediate first-treasure settlement, and exact naira balances (a 100-naira stake pays 300 / 500 / 200 naira for gold / chest / card), replay, timeout settlement, 320/390px overflow, graphics switching, simulated context loss fallback, and renderer idle behaviour. Desktop browser viewport emulation is not physical-phone performance validation.

Screenshots are saved under `output/playwright/doors-3d-*`. TypeScript is checked with `pnpm exec tsc --noEmit` from `apps/web`.

## Rule validation

`pnpm exec vitest run src/app/doors-demo/rules.test.ts --environment node --pool threads --maxWorkers 1` passes all six cases. The default forks worker failed to start in this environment; the explicit threads worker passed. Browser verification also checks first-pick wins, a win after an empty pick, five misses, timeout, modal dismissal, mobile layout and the absence of legacy credit labels.
