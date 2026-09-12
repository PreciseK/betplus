"""Heritage's four outcome tiers (PRD §9.4) and the structural match-count each implies.

The tier NAMES and their match counts are a fact about the 9-tile/5-winner board's
combinatorics, not configuration (REQ-GEC-020 makes probabilities and payouts
configuration; it does not make the game's own shape configurable — mirrors
BlackRedEngine hardcoding "each position is a fair 50/50 draw" as a structural fact,
checked by the platform's publication gate rather than supplied per request). A prize
table naming a tier outside this set is rejected as malformed input.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal

from engine_heritage import seed as seedmod

OutcomeType = Literal["cash", "draw_entry", "none"]

# TIER_LOSS spans 1-3 matches. 4 matches is TIER_HIGH (half stake back).
# 5 matches is TIER_JACKPOT. 0 matches is structurally impossible on a 9-tile/5-winner board.
TIER_MATCH_COUNTS: dict[str, tuple[int, ...]] = {
    "TIER_JACKPOT": (5,),
    "TIER_HIGH": (4,),
    "TIER_LOSS": (1, 2, 3),
}


@dataclass(frozen=True)
class PrizeTier:
    name: str
    probability_basis_points: int
    multiplier_hundredths: int
    outcome_type: OutcomeType


def validate_prize_table(tiers: list[PrizeTier]) -> None:
    names = [t.name for t in tiers]
    if set(names) != set(TIER_MATCH_COUNTS.keys()):
        raise ValueError(
            f"Prize table must contain exactly the tiers {sorted(TIER_MATCH_COUNTS)}, got {sorted(names)}."
        )
    if len(names) != len(set(names)):
        raise ValueError("Prize table has a duplicate tier name.")
    total_bp = sum(t.probability_basis_points for t in tiers)
    if total_bp != 10_000:
        raise ValueError(f"Tier probabilities must sum to exactly 10000 basis points, got {total_bp}.")


def draw_tier(seed: bytes, counter: int, tiers: list[PrizeTier]) -> PrizeTier:
    """Weighted draw over basis points. Tier order must be stable across calls with
    the same prize table for determinism — callers should always send tiers in the
    same (e.g. name-sorted) order for a given prizeTableVersion."""
    total_bp = sum(t.probability_basis_points for t in tiers)
    roll = seedmod.choose_index(seed, counter, total_bp)
    cumulative = 0
    for tier in tiers:
        cumulative += tier.probability_basis_points
        if roll < cumulative:
            return tier
    return tiers[-1]  # unreachable if validate_prize_table() passed


def draw_match_count(seed: bytes, counter: int, tier_name: str) -> int:
    options = TIER_MATCH_COUNTS[tier_name]
    if len(options) == 1:
        return options[0]
    idx = seedmod.choose_index(seed, counter, len(options))
    return options[idx]
