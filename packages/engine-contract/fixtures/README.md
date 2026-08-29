# engine-contract/fixtures

`(seed, ticket_id, input) → expected output` fixtures, one set per game. Used to prove
byte-identical determinism (`REQ-GEC-001`) across hosts, restarts and engine versions.
Consumed by `apps/platform/tests/Contract/` and by each engine's own tests.
