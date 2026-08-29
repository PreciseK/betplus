"""Mirrors tests/Unit/EnginePurityTest.php: the outcome-determination modules import
nothing that could make resolve() non-pure (REQ-GEC-001/002) or non-deterministic
(REQ-RNG-008 bans the `random` module on this path specifically).

app.py is deliberately excluded — it imports fastapi/pydantic for request/response
shaping, which is fine; those never touch the outcome computation itself.
"""

from __future__ import annotations

import ast
from pathlib import Path

FORBIDDEN_IMPORTS = {
    "random",
    "os",  # os.urandom is fine in tests, never in the engine's own outcome path
    "socket",
    "requests",
    "httpx",
    "sqlite3",
    "psycopg2",
}

PURE_MODULES = ["seed.py", "tiers.py", "board.py", "digest.py", "engine.py", "models.py"]


def _imported_names(path: Path) -> set[str]:
    tree = ast.parse(path.read_text(encoding="utf-8"))
    names: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            names.update(alias.name.split(".")[0] for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            names.add(node.module.split(".")[0])
    return names


def test_no_pure_module_imports_something_that_would_break_purity() -> None:
    src_dir = Path(__file__).parent.parent / "src" / "engine_heritage"
    for filename in PURE_MODULES:
        imported = _imported_names(src_dir / filename)
        offending = imported & FORBIDDEN_IMPORTS
        assert not offending, f"{filename} imports {offending}, which breaks engine purity (REQ-GEC-002/REQ-RNG-008)."


def test_no_pure_module_opens_a_file() -> None:
    src_dir = Path(__file__).parent.parent / "src" / "engine_heritage"
    for filename in PURE_MODULES:
        text = (src_dir / filename).read_text(encoding="utf-8")
        assert "open(" not in text, f"{filename} calls open() — engines must not touch the filesystem (REQ-GEC-002)."
