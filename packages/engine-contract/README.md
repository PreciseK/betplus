# engine-contract

The seam that makes a third game an adapter plus configuration, not a re-platform.

- `schema/` — request and response JSON Schema for the Engine Contract (`resolve`, `replay`, `describe`).
- `fixtures/` — `(seed, input) → expected output` pairs per game, executed by `apps/platform/tests/Contract/` and each engine's own test suite to prove conformance.

See architecture.md §"Project Structure & Boundaries" and PRD §6.
