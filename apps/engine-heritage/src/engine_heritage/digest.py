"""sha256 of the canonical outcome (PRD §6.2) — mirrors
Domain/Games/Engine/BlackRed/Digest.php's pipe-joined format, not JSON canonicalisation."""

from __future__ import annotations

import hashlib


def of(seed_hex: str, selected_positions: list[int], board: list[int], winning_positions: list[int], outcome_tier: str) -> str:
    parts = [
        seed_hex,
        ",".join(str(p) for p in selected_positions),
        ",".join(str(n) for n in board),
        ",".join(str(p) for p in winning_positions),
        outcome_tier,
    ]
    return hashlib.sha256("|".join(parts).encode("ascii")).hexdigest()
