# engine-heritage

Epic 7 / architecture.md — Heritage's game engine as its own standalone service, the
platform's second Engine Contract implementation (Python, after BlackRed's PHP). A
pure function of `(seed, stake, prize_table, player_input)`: no database, no external
calls, no internally generated randomness (`REQ-GEC-001..003`).

## Contract

Three endpoints, matching PRD §6 exactly:

```text
POST /engine/v1/resolve
POST /engine/v1/replay
GET  /engine/v1/describe
```

See `src/engine_heritage/models.py` for the request/response shape. The prize table's
tier rows travel inline in the resolve/replay request body — this engine has no
database, so it cannot resolve `prize_table_version` to tiers itself; the platform is
responsible for supplying the actual tiers it fetched for that version.

## Run

```sh
uv sync
PYTHONPATH=src uv run uvicorn engine_heritage.app:app --workers 4 --host 127.0.0.1 --port 8100
```

`PYTHONPATH=src` is required: `tool.uv.package = false` (see pyproject.toml's own comment —
a real Windows dev-host antivirus/filesystem-lock issue with editable installs) means
`engine_heritage` is never installed into site-packages, only importable via that path.
pytest already gets this for free from `[tool.pytest.ini_options] pythonpath = ["src"]`;
uvicorn's CLI doesn't read that file, so it needs the environment variable explicitly.
On Windows PowerShell: `$env:PYTHONPATH="src"; uv run uvicorn ...`.

`--workers N`, never threads (`REQ-HOST-004`, Python GIL). Binds loopback only
(`REQ-SEC-011`, `REQ-HOST-003`) — this is not exposed publicly in any environment.

## Test

```sh
uv run pytest --cov=src --cov-report=term-missing
```

`tests/test_purity.py` asserts the same purity property
`tests/Unit/EnginePurityTest.php` checks on the BlackRed side: no forbidden imports
(sockets, filesystem, database clients, the `random` module) anywhere under
`src/engine_heritage/`.

## Deferred, flagged rather than silently skipped

- **mTLS between the platform and this service** (`REQ-SEC-011`, architecture.md
  D-07). Today the platform's `HeritageEngineClient` calls this over plain loopback
  HTTP with no client certificate — correct for a single-box `REQ-HOST-001` deployment
  where "loopback" already is the trust boundary, but mTLS is real work still owed
  before this crosses a network boundary that isn't a single host's loopback.
- **`uvicorn.conf.py` / systemd unit** for supervised multi-worker deployment
  (architecture.md's `systemd/engine-heritage` entry) — same category of deferred
  infra as Story 1.4's supervised queue workers; the run command above is the
  development/staging equivalent until that unit exists.
- **True Draw mode** (`REQ-HG-016`, SHOULD) — not built. The predetermined-outcome
  model (`REQ-HG-010`) is the only mode this engine implements.
