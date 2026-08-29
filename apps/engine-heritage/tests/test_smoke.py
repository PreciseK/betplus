"""Smoke test. Confirms the package imports and the test/coverage pipeline is wired correctly.

Replaced by real engine tests starting with Story 3.1 (Engine Contract) — this file exists so
`pytest --cov` has something to collect against the scaffold, not as engine test coverage.
"""

import engine_heritage


def test_package_imports() -> None:
    assert engine_heritage is not None
