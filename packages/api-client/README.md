# api-client

The typed HTTP client shared by `apps/web` and `apps/mobile`. This is boundary #2 for
naming conventions — the single place that maps the platform's `snake_case` API payloads
to the `camelCase` used throughout the TypeScript codebase (see architecture.md
"Architectural Boundaries"). Built on `packages/api-types`.
